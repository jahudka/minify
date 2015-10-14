<?php


namespace Minify;


class Helpers {

    public static function getPathAndQuery() {
        @list($path, $queryString) = explode('?', $_SERVER['REQUEST_URI'], 2);
        return [$path, $queryString];

    }

    public static function sanitizePath($path) {
        $tmp = explode('/', trim($path, '/'));
        $path = [];

        while (!empty($tmp)) {
            $u = array_shift($tmp);
            if ($u === '.') {
                continue;

            } else if ($u === '..') {
                array_pop($path);

            } else {
                $path[] = $u;

            }
        }

        return '/' . implode('/', $path);

    }

}
