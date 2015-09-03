<?php


namespace Minify;

class CSSCompressor extends \Minify_CSS_Compressor {

    public static function process($css, $options = array())
    {
        $obj = new static($options);
        return $obj->_process($css);
    }

    /**
     * Constructor
     *
     * @param array $options (currently ignored)
     */
    private function __construct($options) {
        $this->_options = $options;
    }


    protected function _commentCB($m) {
        if ($m[1][0] === '!') {
            return '/*' . $m[1] . '*/';

        }

        return parent::_commentCB($m);

    }


}
