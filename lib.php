<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class repository_entermedia extends repository {

    public function __construct($repositoryid, $context, array $options, $readonly)
    {
        global $SESSION, $PAGE;
        parent::__construct($repositoryid, $context, $options, $readonly);

        $PAGE->requires->js('/repository/entermedia/javascript/jquery.sumoselect.min.js');
        $PAGE->requires->yui_module('moodle-repository_entermedia-filepicker', 'M.repository_entermedia.filepicker.init');

        $PAGE->requires->strings_for_js(array(
            'search_placeholder',
            'search_selectall'
        ), 'repository_entermedia');

        // Handle login
        $this->username = optional_param('entermedia_username', '', PARAM_RAW);
        $this->password = optional_param('entermedia_password', '', PARAM_RAW);
        try{
            if (empty($SESSION->entermediakey) && !empty($this->username) && !empty($this->password)) {
                $response = $this->client()->post('authentication/getkey', array(
                    'json' => array(
                        'id' => $this->username,
                        'password' => $this->password
                    )
                ));

                $body = json_decode($response->getBody());

                if (@$body->response->status === 'ok') {
                    $this->entermediakey = $SESSION->entermediakey = $body->results->entermediakey;
                } else {
                    // Handle wrong login
                }
            } else {
                if (!empty($SESSION->entermediakey)) {
                    $this->entermediakey = $SESSION->entermediakey;
                }
            }
        } catch (GuzzleException $e) {
            $this->logout();
        }
    }

    public function print_login()
    {
        if ($this->options['ajax']) {
            $user_field = new stdClass();
            $user_field->label = get_string('username', 'repository_entermedia').': ';
            $user_field->id    = 'entermedia_username';
            $user_field->type  = 'text';
            $user_field->name  = 'entermedia_username';

            $passwd_field = new stdClass();
            $passwd_field->label = get_string('password', 'repository_entermedia').': ';
            $passwd_field->id    = 'entermedia_password';
            $passwd_field->type  = 'password';
            $passwd_field->name  = 'entermedia_password';

            $ret = array();
            $ret['login'] = array($user_field, $passwd_field);
            $ret['allowcaching'] = true;
            return $ret;
        } else { // Non-AJAX login form - directly output the form elements
            echo '<table>';
            echo '<tr><td><label>'.get_string('username', 'repository_entermedia').'</label></td>';
            echo '<td><input type="text" name="entermedia_username" /></td></tr>';
            echo '<tr><td><label>'.get_string('password', 'repository_entermedia').'</label></td>';
            echo '<td><input type="password" name="entermedia_password" /></td></tr>';
            echo '</table>';
            echo '<input type="submit" value="Enter" />';
        }
    }

    public function logout() { // Taken from repository_alfresco
        global $SESSION;
        unset($SESSION->entermediakey);
        return $this->print_login();
    }

    public function check_login() { // Taken from repository_alfresco
        global $SESSION;
        return !empty($SESSION->entermediakey);
    }

    public function supported_returntypes()
    {
        return FILE_INTERNAL;
    }

    /*
     * Downloads the file and saves it in the temporary dir
     *
     * For some reason the default implementation which uses curl to
     * download the file doesn't work, so we use Guzzle instead.
     * The problem is most likely that curl doesn't understand the 'Content-disposition: attachment' header.
     *
     * @return array with elements:
     *   path: internal location of the file
     *   url: URL to the source (from parameters)
     */
    public function get_file($url, $filename = '')
    {
        $path = $this->prepare_file($filename);
        $client = new Client(array());
        $client->get($url, array(
            'sink' => $path
        ));

        return array(
            'path' => $path,
            'url' => $url
        );
    }

    private $session_key;

    private function save_search_param($key, $value) {
        if (empty($_SESSION[$this->session_key])) {
            $_SESSION[$this->session_key] = array();
        }

        $_SESSION[$this->session_key][$key] = $value;
    }

    private function get_search_param($key, $default = '') {
        return isset($_SESSION[$this->session_key]) && isset($_SESSION[$this->session_key][$key]) ? $_SESSION[$this->session_key][$key] : $default;
    }

    public function print_search()
    {
        $output = html_writer::label(get_string('searchrepo', 'repository'),
            'reposearch', false, array('class' => 'accesshide'));
        $output .= html_writer::empty_tag('input', array('type' => 'text',
            'id' => 'reposearch', 'name' => 's', 'value' => $this->get_search_param('s')));
        $output = html_writer::tag('div', $output, array('class' => "fp-def-search"));

        $output .= html_writer::start_div('search_more');

        $output .= html_writer::start_div('filter');
        $output .= html_writer::label(get_string('assettype', 'repository_entermedia'), 'assettype');
        $output .= html_writer::select(array(
            'audio' => get_string('assettype_audio', 'repository_entermedia'),
            'document' => get_string('assettype_document', 'repository_entermedia'),
            'photo' => get_string('assettype_photo', 'repository_entermedia'),
            '1348758017361' => get_string('assettype_video', 'repository_entermedia'), // Wat ...
        ), 'assettype[]', $this->get_search_param('assettype'), false, array(
            'multiple' => 'multiple',
            'id' => 'assettype'
        ));
        $output .= html_writer::end_div();

        $cache = cache::make('repository_entermedia', 'filters');
        if (!$cache->has('filters')) {
            $filters = json_decode(get_config('entermedia', 'filters'), true);
            $cache->set('filters', $filters);
        }
        $filters = $cache->get('filters');

        foreach ($filters as $name => $options) {
            $output .= html_writer::start_div('filter');
            $output .= html_writer::label($name, $name);

            $output .= html_writer::select($options, $name . '[]', $this->get_search_param($name), false, array(
                'id' => $name,
                'multiple' => 'multiple'
            ));

            $output .= html_writer::end_div();
        }

        $output .= html_writer::start_div('filter');
        $output .= html_writer::label(get_string('age', 'repository_entermedia'), 'age');
        $output .= html_writer::select(array(
            -1 => get_string('age_all_time', 'repository_entermedia'),
            7 => get_string('age_one_week', 'repository_entermedia'),
            14 => get_string('age_two_weeks', 'repository_entermedia'),
            30 => get_string('age_one_month', 'repository_entermedia'),
            60 => get_string('age_two_months', 'repository_entermedia'),
            90 => get_string('age_three_months', 'repository_entermedia'),
            180 => get_string('age_six_months', 'repository_entermedia'),
            360 => get_string('age_one_year', 'repository_entermedia'),
        ), 'age', $this->get_search_param('age'), false, array(
            'id' => 'age'
        ));

        $output .= html_writer::end_div() . html_writer::end_div();

        $output .= html_writer::tag('button', get_string('search'));

        // No other way to include css ...
        $output .= '<style type="text/css">
            .repository_entermedia .fp-navbar {
                overflow: visible;
            }
            .repository_entermedia .fp-navbar > div {
                height: 130px;
            }

            .fp-toolbar .fp-tb-search {
                width: 300px;
                overflow: visible;
            }

            .fp-tb-search .filter {
                width: 220px;
            }

            .fp-tb-search .filter .SumoSelect {
                float: right;
            }

            .repository_entermedia .fp-content {
                height: 385px;
            }

            .fp-tb-search .search_more {
                width: 680px;
            }

            .fp-tb-search .search_more div.filter label {
                font-size: 11px;
            }

            .fp-tb-search .search_more div.filter > label {
                float: left;
                width: 90px;
                text-overflow: ellipsis;
                overflow: hidden;
                padding-top: 10px;
                margin-right: 5px;
                margin-bottom: 10px;
            }

            .SumoSelect .SlectBox {
                width: 100px;
            }

            .fp-toolbar .fp-tb-search input#reposearch {
                width: 180px;
            }

            .fp-toolbar .fp-tb-search a {
                font-weight: bold;
                font-size: 14px;
            }
            </style>';
        $output .= '<link rel="stylesheet" type="text/css" href="/repository/entermedia/style/sumoselect.css" />';

        return $output;
    }

    public function search($search_text, $page = 0) {
        $page = empty($page) ? 1 : $page;

        if ($search_text == get_string('search', 'repository')) {
            $search_text = '';
        }

        $this->save_search_param('s', $search_text);

        $terms = array(
            array(
                'field' => 'description',
                'operator' => 'freeform',
                'value' => $search_text
            )
        );
        $query = array(
            'page' => (string)$page,
            'hitsperpage' => "20",
        );

        if (($assettype = optional_param_array('assettype', false, PARAM_TEXT))) {
            $terms[] = array(
                'field' => 'assettype',
                'operator' => 'matches',
                'value' => implode('|', $assettype)
            );
        }
        $this->save_search_param('assettype', $assettype);

        $cache = cache::make('repository_entermedia', 'filters');
        if (!$cache->has('filters')) {
            $filters = json_decode(get_config('entermedia', 'filters'), true);
            $cache->set('filters', $filters);
        }
        $filters = $cache->get('filters');

        $values = array();
        foreach ($filters as $name => $options) {
            if (($value = optional_param_array($name, false, PARAM_TEXT))) {

                $values = array_merge($values, $value);
            }

            $this->save_search_param($name, $value);
        }

        if (!empty($values)) {
            $terms[] = array(
                'field' => 'keywords',
                'operator' => 'andgroup',
                'values' => $values
            );
        }

        if (($age = optional_param('age', false, PARAM_INT)) && $age !== -1) {

            $terms[] = array(
                'field' => 'assetaddeddate',
                'operator' => 'betweendates',
                'value' => ''
            );

            $query['assetaddeddate.before'] = date('d/m/Y');
            $query['assetaddeddate.after'] = date('d/m/Y', strtotime('-' . $age . ' days'));
        }
        $this->save_search_param('age', $age);

        $query['query'] = array('terms' => $terms);
        try {
            $response = $this->client()->post('module/asset/search', array(
                'json' => $query
            ));
        } catch (GuzzleException $ex) {
            echo (string)$ex->getResponse()->getBody();
        }

        $body = json_decode($response->getBody());

        $list = array();
        if ($body->response->status === 'ok') {
            foreach ($body->results as $result) {
                $list[] = array(
                    'title' =>  $result->name,
                    'shorttitle' =>  $result->longcaption,
                    'datemodified' => strtotime($result->assetmodificationdate),
                    'datecreated' => strtotime($result->assetaddeddate),
                    'size' => $result->filesize,
                    'thumbnail' => $this->get_thumbnail($result),
                    'url' => $this->get_url($result),
                    'source' => $this->get_url($result),
                );
            }

            return array(
                'page' => $page,
                'pages' => $body->response->pages,
                'list' => $list
            );
        } else {
            // TODO
        }
    }

    public function get_listing($path = '', $page = '')
    {
        return $this->search('*', $page);
    }

    // SETTINGS
    public static function get_type_option_names()
    {
        return array_merge(parent::get_type_option_names(), array('uri', 'filters'));
    }

    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform, $classname);

        $mform->addElement('text', 'uri', get_string('uri', 'repository_entermedia'), array('size' => '40'));
        $mform->setType('uri', PARAM_URL);

        $mform->addElement('textarea', 'filters', get_string('filters', 'repository_entermedia'));
        $mform->setType('filters', PARAM_TEXT);
    }

    public static function type_form_validation($mform, $data, $errors)
    {
        if (!empty($data['filters']) && !json_decode($data['filters'])) {
            $errors['filters'] = get_string('invalid_filters', 'repository_entermedia');
        }

        try {
            $client = new Client(array(
                'base_uri' => $data['uri'] . 'mediadb/services/'
            ));

            $response = $client->get('system/systemstatus');

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody());

                if (!$body || $body->response->status !== 'ok') {
                    $errors['uri'] = get_string('uri_notok', 'repository_entermedia');
                }
            } else {
                $errors['uri'] = get_string('uri_statuscode', 'repository_entermedia') . $response->getStatusCode();
            }
        } catch (GuzzleException $e) {
            $errors['uri'] = get_string('uri_exception', 'repository_entermedia');
        }

        // A little bit hacky to empty the cache here...
        $cache = cache::make('repository_entermedia', 'filters');
        $cache->delete('filters');

        return parent::type_form_validation($mform, $data, $errors);
    }

    // UTIL
    private function client() {
        $params = array(
            'base_uri' => get_config('entermedia', 'uri') . 'mediadb/services/'
        );

        if (!empty($this->entermediakey)) {
            $params['query'] = 'entermedia.key=' . $this->entermediakey;
        }

        return new Client($params);
    }

    private function base_url() {
        // todo - other catalogs than public?
        return get_config('entermedia', 'uri') . 'media/catalogs/public/downloads';
    }

    private function get_url($file) {
        return $this->base_url() . '/originals/' . $file->sourcepath . '/' . $file->name;
    }

    private function get_thumbnail($file) {
        switch ($file->fileformat->name) {
            case 'JPG':
            case 'PNG':

            case 'MOV':
            case 'FLV':
            case 'MP4':

            case 'PPT':
            case 'PDF':
                return $this->base_url() . '/preview/crop/' . $file->sourcepath . '/thumb.jpg';
            default:
                return '';
        }
    }
}