<?php

/**
 * MODX Star Rating
 *
 * @author vanchelo <brezhnev.ivan@yahoo.com>
 */
class StarRating {
    /**
     * @var string Rating main table
     */
    public $rating_table;

    /**
     * @var string Rating votes table
     */
    public $votes_table;

    /**
     * @var DocumentParser A reference to the DocumentParser object
     */
    private $modx;

    /**
     * @var DBAPI
     */
    private $db;

    /**
     * @var array An array of templates chunks
     */
    private $chunks = array();

    /**
     * @var array
     */
    private $config = array();

    /**
     * @var array
     */
    private $lang;

    /**
     * @var self
     */
    private static $scriptsLoaded = false;

    /**
     * Constructor
     *
     * @param DocumentParser $modx
     * @param array          $config
     */
    public function __construct(DocumentParser & $modx, array $config = array()) {
        $this->modx =& $modx;
        $this->db =& $modx->db;

        $this->rating_table = $this->modx->getFullTableName('star_rating');
        $this->votes_table = $this->modx->getFullTableName('star_rating_votes');

        $this->setConfigFromSnippet($config);

        $this->setProperties($this->config);
    }

    public function setConfigFromSnippet(array & $config) {
        $this->config = array_merge(array(
            'lang' => 'ru',
            'width' => 16, // Star width in pixels
            'tpl' => 'template', // Name of template file chunk
            'interval' => 24 * 60 * 60, // The interval between votings in seconds
            'noJs' => false,
            'noCss' => false,
        ), $config);
    }

    /**
     * Set custom properties
     *
     * @param array $config
     */
    private function setProperties(array & $config) {
        $this->setLang($config['lang']);

        if (empty($config['id'])) {
            $config['id'] = $this->modx->documentObject['id'];
        }

        $this->config = array_merge($config, array(
            'path' => __DIR__ . '/',
            'relativePath' => '/assets/snippets/star_rating/',
            'assetsUrl' => MODX_BASE_URL . 'assets/snippets/star_rating/assets/',
            'moduleUrl' => MODX_BASE_URL . 'assets/snippets/star_rating/module/',
            'viewsDir' => __DIR__ . '/views/',
            'connectorUrl' => '/assets/snippets/star_rating/module/connector.php',
            'chunksDir' => __DIR__ . '/chunks/'
        ));
    }

    /**
     * @return StarRatingResponse
     */
    public function process() {
        if (!$this->config['noJs'] && !$this->scriptsLoaded()) {
            $this->modx->regClientHTMLBlock('<script>window.jQuery || document.write(\'<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js">\x3C/script>\');</script>');
            $this->modx->regClientHTMLBlock('<script src="' . $this->config['assetsUrl'] . 'js/script.js"></script>');
            $this->scriptsLoaded(true);
        }

        if (!$this->config['noCss']) {
            $this->modx->regClientStartupHTMLBlock('<link rel="stylesheet" href="' . $this->config['assetsUrl'] . 'css/style.css"/>');
        }

        $output = $this->getRating($this->config['id']);

        return $output;
    }

    public function scriptsLoaded($loaded = false) {
        if (!$loaded) return self::$scriptsLoaded;

        self::$scriptsLoaded = (bool) $loaded;
    }

    public function vote($id, $vote, $tpl) {
        $id = (int) $id;
        $vote = (int) $vote;
        $tpl = base64_decode($tpl);

        if (!$vote || !$id) {
            return $this->response()->error($this->trans('something_went_wrong'));
        }

        $checkVote = $this->checkVote($id);

        if ($checkVote !== true) {
            return $this->response()->error($checkVote);
        }

        return $this->setVote($vote, $id, $tpl);
    }

    /**
     * Check Vote
     *
     * @param int $id
     *
     * @return bool|string
     */
    private function checkVote($id = 0) {
        $id = (int) $id;
        if ($id == 0) {
            return 'Не указан ID';
        }

        $ip = $this->getClientIp();
        if ($ip === false) {
            return 'Возможность голосования для вас закрыта!';
        }

        $checkRes = $this->isResourceExists($id);

        if ($checkRes !== true) {
            return $checkRes;
        }

        $time = time() - $this->config['interval'];
        $query = $this->db->select('*', $this->votes_table, "ip = '{$ip}' AND  rid = {$id} AND time > {$time}");
        if ($this->db->getRecordCount($query)) {
            return $this->trans('already_voted');
        }

        return true;
    }

    /**
     * Set Vote
     *
     * @param int    $vote Vote
     * @param int    $id Resource ID
     * @param string $tpl Template
     *
     * @return array|bool
     */
    private function setVote($vote = 0, $id = 0, $tpl = '') {
        $vote = (int) $vote;
        $id = (int) $id;
        if ($vote == 0 || $id == 0 || $vote > 5) {
            return false;
        }

        $votes = 1;

        $query = $this->db->select('*', $this->rating_table, "rid = {$id}");
        $data = $this->db->getRow($query);

        if ($data) {
            $total = $data['total'] + $vote;
            $votes = $data['votes'] + 1;
            $rating = !empty($total) ? round($total / $votes, 2) : $vote;

            $this->db->update(array(
                'total' => $total,
                'votes' => $votes,
                'rating' => $rating,
            ), $this->rating_table, 'rid=' . $id);
        } else {
            $rating = $vote;

            $this->db->insert(array(
                'rid' => $id,
                'total' => $vote,
                'votes' => $votes,
                'rating' => $rating,
            ), $this->rating_table);
            $total = $vote;
        }

        $this->db->insert(array(
            'rid' => $id,
            'ip' => $this->getClientIp(),
            'vote' => $vote,
            'time' => time()
        ), $this->votes_table);

        $width = intval(round($total / $votes, 2) * $this->config['width']);

        $params = array(
            'id' => $id,
            'width' => $width,
            'total' => $votes,
            'rating' => $rating,
            'tpl' => base64_encode($this->config['tpl']),
        );

        $tpl = $this->getChunk($tpl);

        $output = $this->parseTpl($tpl, $params);

        return $this->response()
            ->message($this->trans('success_voted'))
            ->html($output);
    }

    /**
     * Get Rating
     *
     * @param int $id Resource ID
     *
     * @return array
     */
    private function getRating($id) {
        $query = $this->db->select('*', $this->rating_table, "rid = {$id}");

        $data = $this->db->getRow($query);

        $width = 0;
        $total = 0;
        $rating = 0;

        if ($data) {
            $total = $data['votes'];
            $rating = round($data['total'] / $data['votes'], 2);
            $width = intval($rating * $this->config['width']);
        }

        $params = array(
            'id' => $id,
            'width' => $width,
            'total' => $total,
            'rating' => $rating,
            'tpl' => base64_encode($this->config['tpl']),
        );

        $tpl = $this->getChunk($this->config['tpl']);

        $output = $this->parseTpl($tpl, $params);

        return $output;
    }

    /**
     * Get Client IP Address
     *
     * @return mixed
     */
    private function getClientIp() {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = '';
        }

        return filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * Check the Resource availability by ID
     *
     * @param int $id
     *
     * @return bool|string
     */
    private function isResourceExists($id = 0) {
        $tbl = $this->modx->getFullTableName('site_content');
        $id = intval($id);

        $rs = $this->db->query("SELECT COUNT(*) as total FROM {$tbl} WHERE id={$id} AND published=1 AND deleted=0");
        $total = $this->db->getRow($rs);

        if (!intval($total['total'])) {
            return $this->trans('resource_not_found');
        }

        return true;
    }

    /**
     * Star width
     *
     * @param int $width width in pixels
     */
    public function setWidth($width) {
        $this->config['width'] = (int) $width;
    }

    /**
     * Set Interval in seconds before next vote
     *
     * @param int $interval Seconds
     */
    public function setInterval($interval) {
        $this->config['interval'] = (int) $interval;
    }

    /**
     * @param string $tpl
     */
    public function setTemplate($tpl = '') {
        if (!empty($tpl)) {
            $this->config['tpl'] = (string) $tpl;
        }
    }

    /**
     * @param string $lang
     */
    public function setLang($lang) {
        if ($lang) {
            $this->config['lang'] = (string) $lang;
        }

        $file = __DIR__ . '/langs/' . $this->config['lang'] . '.php';
        if (!file_exists($file)) {
            $file = __DIR__ . '/langs/ru.php';
        }

        $this->lang = require $file;
    }

    /**
     * Get template chunk
     *
     * @access private
     *
     * @param string $tpl Template file
     * @param string $postfix Chunk postfix if use file-based chunks
     *
     * @return string Empty
     */
    private function getChunk($tpl = '', $postfix = '.chunk.tpl') {
        if (empty($tpl)) {
            return '';
        }

        if (isset($this->chunks[$tpl])) {
            return $this->chunks[$tpl];
        }

        if (substr($tpl, 0, 7) == "@CHUNK:") {
            $tpl = substr($tpl, 7);

            if ($chunk = $this->modx->getChunk($tpl)) {
                $this->chunks[$tpl] = $chunk;

                return $chunk;
            }
        }

        if (!preg_match('/^[a-z]+$/i', $tpl)) {
            $tpl = 'template';
        }

        // Use file-based chunk
        $file = $this->config['chunksDir'] . strtolower($tpl) . $postfix;
        if (file_exists($file)) {
            return $this->chunks[$tpl] = file_get_contents($file);
        }

        return '';
    }

    /**
     * Parse Template
     *
     * @param string $tpl
     * @param array  $params
     *
     * @return mixed
     */
    private function parseTpl($tpl = '', $params = array()) {
        foreach ($params as $n => $v) {
            $tpl = str_replace('[+' . $n . '+]', $v, $tpl);
        }
        $tpl = preg_replace('~\[\+(.*?)\+\]~', '', $tpl);

        return $tpl;
    }

    /**
     * @param string $message
     * @param bool   $error
     * @param array  $data
     *
     * @return StarRatingResponse
     */
    public function response($message = '', $error = false, $data = array()) {
        if (!class_exists('StarRatingResponse')) {
            require_once 'starratingresponse.class.php';
        }

        $response = new StarRatingResponse($message, $error, $data);

        return $response;
    }

    public function isInstalled() {
        $prefix = $this->db->config['table_prefix'];

        $q = $this->db->query("SHOW TABLES LIKE '{$prefix}star_%'");

        return $this->db->getRecordCount($q) == 2;
    }

    public function install() {
        if ($this->isInstalled())
            return false;

        $tbl_prefix = $this->db->config['table_prefix'];

        $sqlfile = $this->config['path'] . 'setup.sql';

        if (!file_exists($sqlfile) || !is_readable($sqlfile)) {
            return $this->trans('setup.sql_does_not_exists');
        }

        $sql = str_replace('{prefix}', $tbl_prefix, file_get_contents($sqlfile));

        $matches = array();
        preg_match_all('/CREATE TABLE.*?;/ims', $sql, $matches);

        $this->db->query('SET AUTOCOMMIT=0;');
        $this->db->query('START TRANSACTION;');
        $errors = false;

        foreach ($matches[0] as $sqlcmd) {
            $rs = $this->db->query($sqlcmd);
            if (!$rs) {
                $errors = true;
                break;
            }
        }

        if ($errors) {
            $this->db->query('ROLLBACK;');
        } else {
            $this->db->query('COMMIT;');
        }

        $this->db->query('SET AUTOCOMMIT=1;');

        if ($errors) {
            return 'Ошибка установки модуля. Повторите попытку. Если ошибка не исчезнет напишите об ошибке <a href="https://github.com/vanchelo/MODX-Evolution-Star-Rating/issues">сюда</a>';
        }

        unlink($sqlfile);

        return 'Все таблицы в базе данных успешно созданы.';
    }

    /**
     * @return DocumentParser
     */
    public function getModx() {
        return $this->modx;
    }

    /**
     * @return DBAPI
     */
    public function getDB() {
        return $this->db;
    }

    /**
     * Получение языковой строки
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public function trans($key, $default = '') {
        return isset($this->lang[$key]) ? $this->lang[$key] : $default;
    }

    /**
     * @param null  $tpl
     * @param array $data
     *
     * @return string|StarRatingView
     */
    public function view($tpl = null, $data = array()) {
        static $view;
        if (!isset($view)) {
            require_once 'starratingview.class.php';
            $view = new StarRatingView($this->config['viewsDir']);
            $view->share('app', $this);
            $view->share('modx', $this->modx);
        }

        if ($tpl !== null) {
            return $view->fetchPartial($tpl, $data);
        }

        return $view;
    }

    /**
     * @param string $string
     *
     * @return string
     */
    public function e($string) {
        return htmlentities($string, ENT_QUOTES, 'UTF-8', false);
    }

    /**
     * Проверяет AJAX запрос или нет
     *
     * @return bool
     */
    public function ajax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest' ? true : false;
    }

    /**
     * Проверяет каким методом пришел запрос
     *
     * @param string $method
     *
     * @return bool
     */
    public function method($method = 'get') {
        return strtolower($_SERVER['REQUEST_METHOD']) === strtolower($method);
    }

    /**
     * @param string $key
     * @param null   $default
     *
     * @return null
     */
    public function getConfig($key, $default = null) {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

}
