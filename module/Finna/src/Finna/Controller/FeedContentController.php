<?php
/**
 * Feed Content Controller
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2011.
 * Copyright (C) The National Library of Finland 2014-2023.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.    See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA    02111-1307    USA
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
namespace Finna\Controller;

/**
 * Loads feed content pages
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class FeedContentController extends ContentController
{
    use Feature\DownloadTrait;

    /**
     * Default action if none provided
     *
     * @return Laminas\View\Model\ViewModel
     */
    public function contentAction()
    {
        $event = $this->getEvent();
        $routeMatch = $event->getRouteMatch();
        $page = $routeMatch->getParam('page');
        $element = $routeMatch->getParam('element')
            ?? $this->params()->fromQuery('element');
        $feedUrl = $this->params()->fromQuery('feedUrl', false);
        $rssConfig = $this->serviceLocator->get(\VuFind\Config\PluginManager::class)
            ->get($feedUrl ? 'rss-organisation-page' : 'rss');

        if (!isset($rssConfig[$page])) {
            return $this->notFoundAction();
        }

        $config = $rssConfig[$page];
        $modal = isset($config->linkTo) && $config->linkTo == 'modal';

        return $this->createViewModel(
            ['page' => 'feed-content', 'feed' => $page,
             'element' => $element, 'modal' => $modal, 'feedUrl' => $feedUrl]
        );
    }

    /**
     * Linked events action
     *
     * @return Laminas\View\Model\ViewModel
     */
    public function linkedEventsAction()
    {
        $event = $this->params()->fromQuery();
        return $this->createViewModel(
            ['page' => 'linked-events', 'event' => $event]
        );
    }

    /**
     * Proxy load feed image
     *
     * @return Laminas\View\Model\ViewModel
     */
    public function imageAction()
    {
        $event = $this->getEvent();
        $routeMatch = $event->getRouteMatch();
        $id = $routeMatch->getParam('page');
        if (!($imageUrl = $this->params()->fromQuery('image'))) {
            return $this->notFoundAction();
        }

        $url = $this->serviceLocator->get('ControllerPluginManager')->get('url');
        $serverUrlHelper = $this->getViewRenderer()->plugin('serverurl');
        $homeUrl = $serverUrlHelper($url->fromRoute('home'));

        $feedService = $this->serviceLocator->get(\Finna\Feed\Feed::class);
        if ($config = $feedService->getFeedConfig($id)) {
            $config = $config['result'];
        }

        // Only handle a normal feed:
        $feed = null;
        if (!isset($config['ilsList'])) {
            $feed = $feedService->readFeed($id, $homeUrl);
        }

        if (!$feed) {
            return $this->notFoundAction();
        }

        // Validate image url to ensure we don't proxy anything not belonging to the
        // feed:
        $valid = false;
        $proxiedUrl = $feedService->proxifyImageUrl($imageUrl, $id);
        foreach ($feed['items'] as $item) {
            if (($item['image']['url'] ?? '') === $proxiedUrl) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return $this->notFoundAction();
        }

        if (!($imageResult = $this->downloadData($imageUrl))
            || !$this->isImageContentType($imageResult['contentType'])
        ) {
            return $this->notFoundAction();
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', $imageResult['contentType']);
        $this->setCachingHeaders($headers);
        $response->setContent($imageResult['content']);
        return $response;
    }

    /**
     * Proxy load linked event image
     *
     * @return Laminas\View\Model\ViewModel
     */
    public function eventImageAction()
    {
        $params = $this->params()->fromQuery();
        if (!($imageUrl = $params['image'] ?? '')) {
            return $this->notFoundAction();
        }
        unset($params['image']);

        $linkedEvents = $this->serviceLocator->get(\Finna\Feed\LinkedEvents::class);
        try {
            $events = $linkedEvents->getEvents($params);
        } catch (\Exception $e) {
            return $this->notFoundAction();
        }

        // Validate image url to ensure we don't proxy anything not belonging to the
        // feed:
        $valid = false;
        $proxiedUrl = $linkedEvents->proxifyImageUrl($imageUrl, $params);
        foreach ($events['events'] as $event) {
            if (($event['image']['url'] ?? '') === $proxiedUrl) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return $this->notFoundAction();
        }

        if (!($imageResult = $this->downloadData($imageUrl))
            || !$this->isImageContentType($imageResult['contentType'])
        ) {
            return $this->notFoundAction();
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', $imageResult['contentType']);
        $this->setCachingHeaders($headers);
        $response->setContent($imageResult['content']);
        return $response;
    }
}
