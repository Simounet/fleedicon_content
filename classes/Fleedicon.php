<?php

class Fleedicon {
    const DEFAULT_EXTENSION = 'png';
    CONST FAVICONS_FOLDER = 'favicons/';
    CONST LOGS_FOLDER     = 'logs/';
    CONST NO_FAVICON_LOG_FILE = self::LOGS_FOLDER . 'no-favicon';
    CONST CHECK_DATE_FILE     = self::LOGS_FOLDER . 'check';

    protected $feed_id;

    protected $plugin_path;
    protected $base_path;
    protected $icon_path;
    protected $icon_path_without_extension;
    protected $icon_exists;
    protected $default_icon_path;

    protected $today;

    protected $debug;

    public function __construct($feed_id, $path, $debug=false) {

        $this->feed_id = $feed_id;
        $this->plugin_path = $path;

        $this->base_path = $this->plugin_path . self::FAVICONS_FOLDER;
        $this->icon_path_without_extension = $this->base_path . $this->feed_id;

        $this->icon_path = $this->getIconPath();
        $this->icon_exists = file_exists( $this->icon_path );
        $this->default_icon_path = $this->base_path . 'default.png';

        $this->today = new DateTime( date('Y-m-d') );

        $this->debug = $debug;
    }

    public function action() {
        if( (   $this->icon_exists === false 
             && $this->today > $this->getNewCheckDate()
            )
            || $this->debug ) {
            $this->setFavicon();
        }

        if( $this->icon_exists === false ) {
            $this->icon_path = $this->default_icon_path;
        }

        return '<img src="' . $this->icon_path . '" width="16" height="16" alt="" class="feed-icon" />';
    }

    public function setFavicon($set_check_date=true, $url=false) {

        if(!$url) {
            $f = new Feed();
            $f = $f->getById($this->feed_id);

            $url = $f->getWebsite();

            if (!$url) {
                $url = $f->getUrl();
            }
        }

        if($url) {
            $favicon = $this->getFaviconFromUrl($url);

            if($favicon !== false) {
                $extension = pathinfo($favicon['url'])['extension'] ?? self::DEFAULT_EXTENSION;
                $image = $this->getIconPath($extension);
                file_put_contents($image, $favicon['file']);
            } else {
                file_put_contents($this->plugin_path . self::NO_FAVICON_LOG_FILE, $url . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        if($set_check_date===true) {
            $this->setCheckDate($this->today->format('Y-m-d'));
        }

    }

    public static function setAllFavicons($plugin_path = './') {
        self::createErrorsFile($plugin_path);
        $feed = new Feed();
        $conditions = 'SELECT id, website FROM `' . MYSQL_PREFIX .  'feed` ;';
        $query = $feed->customQuery($conditions);

        while( $feed = $query->fetch_assoc() ) {
            $fleedicon = new Fleedicon($feed['id'], $plugin_path);
            if(!$fleedicon->icon_exists) {
                $fleedicon->setFavicon(true, $feed['website']);
            }
        }
    }

    public function removeFavicon( $path = false ) {
        if( ! $path ) {
            $path = $this->getExistingFavicon();
        }

        self::deleteFavicon( $path );
    }

    public static function removeAllFavicons($plugin_path) {
        $favicons_path = $plugin_path . self::FAVICONS_FOLDER;
        if (file_exists($favicons_path)) {
            $favicons = preg_grep('/default\.png$/', glob($favicons_path.'*'), PREG_GREP_INVERT);
            foreach ($favicons as $favicon) {
                self::deleteFavicon( $favicon );
            }
        }
   }

    protected static function deleteFavicon( $path ) {
        if( ! file_exists( $path ) ) {
            return false;
        }
        unlink( $path );
    }

    protected function setCheckDate($date) {
        $filepath = $this->plugin_path . self::CHECK_DATE_FILE;
        self::createFileIfNotExists($filepath);
        file_put_contents( $filepath, $date );
    }

    protected function getCheckDate() {
        $content = file_get_contents( $this->plugin_path . self::CHECK_DATE_FILE );
        return $content;
    }

    public static function removeCheckDateFile($plugin_path) {
        self::removeFile($plugin_path . self::CHECK_DATE_FILE);
    }

    protected function createErrorsFile($plugin_path) {
        $errors_file = $plugin_path . self::NO_FAVICON_LOG_FILE;
        self::removeFile($errors_file);
        self::createFileIfNotExists($errors_file);
    }

    protected static function createFileIfNotExists($filepath) {
        if(!file_exists($filepath)) {
            touch($filepath);
        }
    }

    protected static function removeFile($path) {
        if(file_exists($path)) {
            unlink($path);
        }
    }

    protected function getFaviconFromUrl($url) {
        // Helped by: https://github.com/gokercebeci/geticon/blob/master/class.geticon.php
        $logs[] = 'in: ' . $url;

        $user_agent = $this->getUserAgent();

        $context = stream_context_create(
                array (
                    'http' => array (
                        'follow_location' => true, // don't follow redirects
                        'user_agent' => $user_agent
                    )
                )
            );
        $html = @file_get_contents($url, false, $context);
        //$html = @stream_get_contents(fopen($url, "rb"));
        $logs[] = '<pre>' . print_r( $html, true ) . '</pre>';
        if (preg_match('/<([^>]*)link([^>]*)rel\=("|\')?(icon|shortcut icon)("|\')?([^>]*)>/iU', $html, $out)) {
            $logs[] = 'match out: <pre>' . print_r( $out, true ) . '</pre>';
            if (preg_match('/href([s]*)=([s]*)"([^"]*)"/iU', $out[0], $out)) {
                $logs[] = 'match out2: <pre>' . print_r( $out, true ) . '</pre>';
                $ico_href = trim($out[3]);
                $logs[] = 'ico href: <pre>' . print_r( $out, true ) . '</pre>';
                if (preg_match('/(http)(s)?(:\/\/)/', $ico_href, $matches, PREG_OFFSET_CAPTURE)) {
                    $ico_url = $ico_href;
                    $logs[] = 'ico url: ' . $ico_url;
                } elseif (preg_match('/(\/\/)/', $ico_href, $matches, PREG_OFFSET_CAPTURE)) {
                    $ico_url = 'http:' . $ico_href;
                    $logs[] = 'ico url2: ' . $ico_url;
                } else {
                    if( strpos( $ico_href, '/' ) === 0 ) {
                        // If the icon url starts with /
                        // We use the host to form the absolute URL
                        $parsed_url = parse_url( $url );
                        $ico_url = $parsed_url['scheme'] . '://' . $parsed_url['host']  . $ico_href;
                    } else {
                        // If not, we are using the relative path
                        $ico_url = $url . ltrim($ico_href, '/');
                    }
                    $logs[] = 'else: ' . $ico_url;
                }
            }
        }

        if(!isset($ico_url)) {
            $parsed_url = parse_url($url);
            $base_url = $parsed_url['scheme'].'://'.$parsed_url['host'];
            $ico_url = $base_url . '/favicon.ico';
        }

        if($this->debug) {
            foreach($logs as $log) {
                echo $log;
            }
        }

        return $this->getImage($ico_url);
    }

    protected function getImage($url) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $user_agent = $this->getUserAgent();
        $header_options = array(
          'http' => // The wrapper to be used
            array(
            'method'  => 'GET', // Request Method
            'header' => "Referer: " . $_SERVER["HTTP_HOST"] . $_SERVER['REQUEST_URI'] . "\r\n",
            'user_agent' => $user_agent
          )
        );
        $context = stream_context_create( $header_options );

        $file = @file_get_contents($url, false, $context);
        if($file === FALSE) {
            return false;
        }
        $mime_type = $finfo->buffer($file);

        $pos = strpos($mime_type, 'image');

        return $pos !== false ? ['file' => $file, 'url' => $url ] : false;
    }

    protected function getNewCheckDate() {
        $new_check = new DateTime( $this->getCheckDate() );
        $new_check->add( new DateInterval('P1M') );

        return $new_check;
    }

    protected function getUserAgent() {
        $user_agent = 'Leed (X11; Linux i686; rv:37.0) Gecko/20100101 Firefox/37.0';
        if( isset($_SERVER['HTTP_USER_AGENT']) ) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
        }
        return $user_agent;
    }

    private function getIconPath($extension = self::DEFAULT_EXTENSION) {
        $existing = $this->getExistingFavicon();
        return $existing ?? $this->icon_path_without_extension . '.' . $extension;
    }

    private function getExistingFavicon()
    {
        $existingFavicons = glob($this->icon_path_without_extension . '.*');
        return array_shift($existingFavicons);
    }
}
