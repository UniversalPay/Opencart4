<?php

namespace Payments;

class ResponseError extends Response {

    protected $_errors = array();

    public function __construct($response, $request, $info = array()) {
        parent::__construct($response, $info);
        if(isset($this->data["errors"])){
            $this->_errors = new ResponseErrorErrors($this->data["errors"]);
        }else{
            $this->_errors = 'connect error';
        }
        $this->_request = $request;
    }

    public function get_error($name = null) {
        if (!is_null($name)) {
            if (isset($this->errors->{$name})) {
                return $this->errors->{$name};
            }
            return NULL;
        }
        return $this->errors;
    }

}
