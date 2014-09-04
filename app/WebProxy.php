<?php
require_once "Curl/Curl.php";
require_once "WebResponse.php";

class WebProxy {
    private $_nrkUrl = "http://www.nrk.no";
    private $_nrkShortUrl = "http://nrk.no";
    private $_seherUrl = "http://www.seher.no";
    private $_seherFeedUrl = "http://www.seher.no/rss-forside.xml/1";

    // Advertisement/Credit for author of this script :)
    private $_adLinkUrl = "https://www.steffenl.com";
    private $_adLinkName = "SteffenL.com";

    public function handleRequest() {
        // We can now do generic content retouching and serve the proxied response
        $response = $this->_executeWebRequest($this->_nrkUrl . $this->_getRequestUri());
        $customResponseContent = $this->_retouchProxiedResponseContent($response);
        $this->_serveProxiedResponse($response, $customResponseContent);
    }

    private function _getScriptUrl() {
        // Apache supports the variable SCRIPT_URL, but IIS doesn't
        // For this reason, we'll just use REQUEST_URI and remove the query string
        $requestUri = $this->_getRequestUri();
        $qsTokenPos = strpos($requestUri, "?");
        if ($qsTokenPos === false) {
            return $requestUri;
        }

        $scriptUrl = substr($requestUri, 0, $qsTokenPos);
        return $scriptUrl;
    }

    private function _getRequestUri() {
        return $_SERVER["REQUEST_URI"];
    }

    private function _getUserAgent() {
        return $_SERVER["HTTP_USER_AGENT"];
    }

    private function _getReferer() {
        return $_SERVER["HTTP_REFERER"];
    }

    private function _executeWebRequest($url) {
        $curl = new \Curl\Curl();
        // Pass on the original client's user agent
        $curl->setUserAgent($this->_getUserAgent());
        // Follow redirects
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        // TODO: Set referer?
        $responseContent = $curl->get($url);
        $curl->close();

        return new WebResponse($curl, $responseContent);
    }

    // Retouches the proxied response for the home page
    private function _retouchProxiedHomeResponse($htmlParser, $domDoc) {
        // This is the fun part: replacing articles with ones from seher.no

        // seher.no articles we can still use to replace with the original articles
        $allOtherArticles = $this->_getFrontPageArticlesFromSeher();
        $otherArticlesPool = $allOtherArticles;

        $articleColumns = $this->_findElementsWithClass($domDoc, "div", "articles");
        foreach ($articleColumns as $articleColumn) {
            $articleContainers = $this->_findElementsWithClass($articleColumn, "div", "article-content");
            foreach ($articleContainers as $articleContainer) {
                $headingElement = $articleContainer->getElementsByTagName("h3")->item(0);
                if (!$headingElement) {
                    // Skip articles that are out of the ordinary
                    continue;
                }

                // This is only the first one, there may be more
                $titleAnchorElement = $headingElement->getElementsByTagName("a")->item(0);
                if (!$titleAnchorElement) {
                    continue;
                }

                $introElement = $articleContainer->getElementsByTagName("p")->item(0);
                if (!$introElement) {
                    continue;
                }

                $introTextElement = $introElement->firstChild;
                if (!$introTextElement) {
                    continue;
                }

                $imageElement = $articleContainer->getElementsByTagName("img")->item(0);
                if (!$imageElement) {
                    continue;
                }

                $hasOriginalIntroText = ($introTextElement->nodeName == "#text");

                // There may not be enough other articles
                $hasOtherArticle = isset($otherArticlesPool[0]);
                if (!$hasOtherArticle) {
                    // Refill the pool of other articles
                    $otherArticlesPool = $allOtherArticles;
                }

                $otherArticle = null;
                $originalArticle = [
                    "title" => $titleAnchorElement->textContent,
                    "intro" => $hasOriginalIntroText ? $introTextElement->textContent : null,
                    "image" => $imageElement->getAttribute("src"),
                    "url" => $titleAnchorElement->getAttribute("href"),
                ];

                // Get a random seher.no article (if any)
                if ($hasOtherArticle) {
                    $otherArticleIndex = array_rand($otherArticlesPool);
                    $otherArticle = $otherArticlesPool[$otherArticleIndex];
                    // Don't use that article anymore
                    unset($otherArticlesPool[$otherArticleIndex]);
                }

                $articleToUse = $hasOtherArticle ? $otherArticle : $originalArticle;

                //
                // Replace the image
                // 
                $imageElement->setAttribute("src", $articleToUse["image"]);
                // Fix aspect ratio
                $imageElement->setAttribute("style", "width: 100%; height: auto");

                //
                // Replace up the original title
                //
                $newTitleTextNode = $domDoc->createDocumentFragment();
                // TODO: Handle HTML and badly formatted HTML in the title?
                $newTitleTextNode->appendXML(<<<HTML
<a href="{$originalArticle["url"]}">{$otherArticle["title"]}</a>
HTML
                );

                // Because NRK' HTML isn't very well structured, we need to recreate the anchor and remove the existing ones (yes, not only one)
                while ($headingElement->hasChildNodes()) {
                    $headingElement->removeChild($headingElement->firstChild);
                }

                $headingElement->insertBefore($newTitleTextNode, $headingElement->firstChild);

                //
                // Replace the intro
                //
                $newintroTextNode = $domDoc->createDocumentFragment();
                // TODO: Handle HTML and badly formatted HTML in the title?
                $newintroTextNode->appendXML(<<<HTML
<span>{$otherArticle["intro"]}</span>
HTML
                );
                $introTextElement->parentNode->insertBefore($newintroTextNode, $introTextElement);
                // Make sure we only remove the text node
                if ($hasOriginalIntroText) {
                    $introTextElement->parentNode->removeChild($introTextElement);
                }
            }
        }
    }

    // Get front page articles from seher.no via RSS feed (limited number of articles)
    // TODO: Remove?
    private function _getRssFrontPageArticlesFromSeher() {
        $feedReader = new PicoFeed\Reader();
        $feedReader->download($this->_seherFeedUrl);
        $parser = $feedReader->getParser();
        $feed = $parser->execute();

        $articles = [];
        foreach ($feed->getItems() as $item) {
            $articles[] = [
                "title" => $item->getTitle(),
                "intro" => $item->getContent(),
                "image" => $item->getEnclosureUrl(),
                "url" => $item->getUrl(),
            ];
        }

        return $articles;
    }

    // Get front page articles from seher.no
    private function _getFrontPageArticlesFromSeher() {
        $response = $this->_executeWebRequest($this->_seherUrl);
        $htmlParser = new \Masterminds\HTML5();
        $domDoc = $htmlParser->loadHTML($response->getContent());

        $articles = [];
        foreach ($this->_findElementsWithClass($domDoc, "div", "article") as $articleContainer) {
            // seher.no has better structured HTML, so I don't care that much about validation
            // TODO: Do more validation
            $titleElement = $this->_findElementWithClass($articleContainer, "div", "title");
            $titleAnchorElement = $titleElement->firstChild;
            $titleTextNode = $titleAnchorElement->firstChild;

            $introElement = $this->_findElementWithClass($articleContainer, "div", "lead")->firstChild;

            $imageElement = $articleContainer->getElementsByTagName("img")->item(0);


            $articles[] = [
                "title" => $titleTextNode->nodeValue,
                "intro" => $introElement->nodeValue,
                // Pretty low-resolution images, much lower than the ones in the RSS feed
                // TODO: Use higher-resolution images
                // No need to fix this URL, but we should normally make sure
                "image" => $imageElement->getAttribute("data-src"),
                // No need to fix this URL, but we should normally make sure
                "url" => $titleAnchorElement->getAttribute("href"),
            ];
        }

        return $articles;
    }

    // This is a poor workaround for the lack of support for DOMQuery or equivalent, because that doesn't seem to work with the HTML parser I use
    // TODO: Find a way to use DOMQuery or equivalent
    private function _findElementWithClass($dom, $tagName, $class) {
        foreach ($dom->getElementsByTagName($tagName) as $e) {
            if (in_array($class, explode(" ", $e->getAttribute("class")))) {
                return $e;
            }
        }
    }

    // This is a poor workaround for the lack of support for DOMQuery or equivalent, because that doesn't seem to work with the HTML parser I use
    // TODO: Find a way to use DOMQuery or equivalent
    private function _findElementsWithClass($dom, $tagName, $class) {
        $elements = [];
        foreach ($dom->getElementsByTagName($tagName) as $e) {
            if (in_array($class, explode(" ", $e->getAttribute("class")))) {
                $elements[] = $e;
            }
        }

        return $elements;
    }

    private function _addPageAdvertisement($htmlParser, $domDoc) {
        // Check that there's a body tag
        $bodyTags = $domDoc->getElementsByTagName("body");
        if (!$bodyTags->length) {
            return;
        }

        $adContent = <<<HTML
<div style="background: #000; color: #fff; padding: 10px; text-align: center">
    Page was proxied via <a style="color: #fff" href="{$this->_adLinkUrl}">{$this->_adLinkName}</a>
</div>
HTML;

        $body = $bodyTags->item(0);
        $adContainer = $domDoc->createDocumentFragment();
        $adContainer->appendXML($adContent);
        $body->insertBefore($adContainer, $body->firstChild);
    }

    private function _retouchProxiedResponseContent($response) {
        // Retouch only content with these types
        $includeContentTypes = [
            "text/html"
        ];

        if (!in_array($response->getContentType(), $includeContentTypes)) {
            // Return untouched content
            return $response->getContent();
        }

        $htmlParser = new \Masterminds\HTML5();
        $domDoc = $htmlParser->loadHTML($response->getContent());

        // Execute handlers for specific pages
        switch ($this->_getScriptUrl()) {
            case "/":
                $this->_retouchProxiedHomeResponse($htmlParser, $domDoc);
                break;
        }

        $this->_addPageAdvertisement($htmlParser, $domDoc);

        $baseUrls = [
            $this->_nrkUrl,
            $this->_nrkShortUrl
        ];

        // Maps tags to attributes that can have URLs that must be fixed
        $tagAttributesMap = [
            "a" => ["href"],
            // TODO: Add more applicable tags
        ];

        foreach ($baseUrls as $baseUrl) {
            foreach ($tagAttributesMap as $tagName => $attributeNames) {
                $elements = $domDoc->getElementsByTagName($tagName);
                foreach ($elements as $e) {
                    foreach ($attributeNames as $attr) {
                        $url = $e->getAttribute($attr);
                        $rebasedUrl = $this->_rebaseRemoteUrl($baseUrl, $url);
                        $e->setAttribute($attr, $rebasedUrl);
                    }
                }
            }
        }

        $customResponseContent = $htmlParser->saveHTML($domDoc);
        return $customResponseContent;
    }

    private function _rebaseRemoteUrl($baseUrl, $url) {
        $baseUrlLength = strlen($baseUrl);
        // Check whether the base URL is actually within the same primary domain
        if (@substr_compare($url, $baseUrl, 0, $baseUrlLength, true)) {
            // No need to rebase
            return $url;
        }

        // Remove the base URL
        return substr_replace($url, "", 0, $baseUrlLength);
    }

    private function _serveProxiedResponse($response, $customContent = null) {
        $content = ($customContent !== null) ? $customContent : $response->getContent();

        http_response_code($response->getStatusCode());
        header("Content-Type: " . $response->getContentType());
        echo $content;
    }
}
