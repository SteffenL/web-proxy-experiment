<?php
class WebResponse {
    private $_curl = null;
    private $_content = null;

    function __construct($curl, $content) {
        $this->_curl = $curl;
        $this->_content = $content;
    }

    public function getContent() {
        return $this->_content;
    }

    public function getContentType() {
        // We only care about the first part of the content type, e.g. text/html
        $parts = explode(";", $this->_curl->response_headers['Content-Type'], 2);
        return isset($parts[0]) ? $parts[0] : null;
    }
}
