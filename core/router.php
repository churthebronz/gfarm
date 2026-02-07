<?php if (!defined('FastCore')) { echo('Выявлена попытка взлома!'); exit(); }

class Router {
    public $title      = '';
    public $params     = [];
    public $classname  = '';
    public $data       = null;
    public $segment    = [];        // always array
    public $request_uri= '';
    public $url_info   = [];
    public $found      = false;

    function __construct() {
        $this->Routed();
    }

    function Routed() {

        $this->classname = '';
        $this->title     = '';
        $this->params    = [];
        $this->segment   = [];

        $map = isset($GLOBALS['routes']) && is_array($GLOBALS['routes']) ? $GLOBALS['routes'] : [];

        // Path → segments
        $this->request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->url_info    = parse_url($this->request_uri ?? '/');
        $uri               = urldecode($this->url_info['path'] ?? '/');
        $segments          = explode('/', trim($uri, '/'));
        if ($segments === [''] || $segments === []) { $segments = []; }

        $data = false;

        foreach ($map as $term => $dd) {
            $match = [];
            $i = @preg_match('@^' . $term . '$@Uu', $uri, $match);
            if ($i > 0) {
                // $dd may be "page,title" or just "page"
                if (is_string($dd)) {
                    $m = explode(',', $dd, 2);
                } elseif (is_array($dd)) {
                    $m = $dd;
                } else {
                    $m = [];
                }

                $data = [
                    'classname' => isset($m[0]) ? strtolower(trim($m[0])) : '',
                    'title'     => isset($m[1]) ? trim($m[1]) : '',
                    'params'    => $match,
                    'segment'   => $segments,
                ];
                break;
            }
        }

        if ($data === false) {
            // 404
            if (isset($map['_404'])) {
                $dd = $map['_404'];
                if (is_string($dd)) {
                    $m = explode(',', $dd, 2);
                } elseif (is_array($dd)) {
                    $m = $dd;
                } else {
                    $m = [];
                }
                $this->classname = strtolower(trim($m[0] ?? '404'));
                $this->title     = trim($m[1] ?? '');
                $this->params    = [];
                $this->segment   = $segments;
            }
            $this->found = false;
        } else {
            // Found
            $this->classname = $data['classname'];
            $this->title     = $data['title'];
            $this->params    = is_array($data['params']) ? $data['params'] : [];
            $this->segment   = is_array($data['segment']) ? $data['segment'] : [];
            $this->found     = true;
        }
        return $this->classname;
    }
}
?>
