<?php
class WebResponse {
    private $_curl = null;

    function __construct($curl) {
        $this->_curl = $curl;
    }

    public function getContent() {
        return $this->_curl->raw_response;
    }

    public function getContentType() {
        // We only care about the first part of the content type, e.g. text/html
        $parts = explode(";", $this->_curl->response_headers['Content-Type'], 2);
        return isset($parts[0]) ? $parts[0] : null;
    }

    public function getStatusCode() {
        return $this->_curl->http_status_code;
    }
}
