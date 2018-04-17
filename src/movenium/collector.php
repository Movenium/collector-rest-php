<?php

namespace movenium;

class collector {

    var $ok_http_codes = array(200,201,204);
    var $apiurl = "https://api.movenium.com/1.1/";
    var $access_token = null;
    var $default_client_id = "openapi";
    var $allow_error = false;
    var $outputFormat = null;
    var $headers = array();
    var $last_count = 0;

    var $debug_mode = false;

    public function set_accesstoken_directly($access_token, $url = null) {
        if ($url) $this->apiurl = $url;
        $this->access_token = $access_token;
    }

    public function login($username, $password, $client_id = null, $url = null) {
        if ($url) $this->apiurl = $url;
        if (!$client_id) $client_id = $this->default_client_id;
        $grant = array(
            "username" => $username,
            "password" => $password,
            "grant_type" => "password",
            "client_id" => $client_id
        );
        $back = $this->request("post", "login", http_build_query($grant));
        //print_r($back);
        $this->access_token = $back['access_token'];

        if ($this->access_token)
            return true;
        else
            return $back;
    }

    public function setHeader($header, $value) {
        $this->headers[$header] = $value;
    }

    public function clear_headers() {
        $this->headers = array();
    }

    /**
     *
     * @param type $form
     * @param type $params
     * @param array $sideload ie. {project: [name,number]}
     * @return type
     */
    public function findAll($form, $params = array()) {
        $form = $this->pluralize($form);

        $sideload = array_key_exists('sideload', $params) ? $params['sideload'] : null;

        if (is_array($sideload)) {
            unset($params['sideload']);
            $params = array_merge($this->create_sideload_get($sideload), $params);
        }

        $path = $this->camelCase($form);
        if (count($params) > 0)
            $path .= '?'.http_build_query($params);

        $back = $this->request("get", $path, $params);

        if ($this->allow_error && array_key_exists('error', $back))
            return $back;
        if(array_key_exists("count", $back)) {
            $this->last_count = $back["count"];
        }
        if ($this->outputFormat == "raw")
            return $back;
        else if (is_array($sideload))
            return $this->populate_sideload($back, $form, $sideload);
        else if ($sideload)
            return $this->auto_populate_sideload($back, $form);
        else
            return $back[$form];
    }

    public function auto_populate_sideload($data, $form) {
        $rows = $data[$form];
        $data_by_ids = array();
        if (count($rows) < 1) return $rows;
        foreach ($data as $form_name => $subrows) {
            if ($form_name == $form) continue;
            if (!is_array($subrows)) continue;

            foreach ($subrows as $values) {
                $rowid = $values['id'];
                unset($values['id']);
                $data_by_ids[$rowid] = implode(" ", $values);
            }
        }

        foreach ($rows as $key => $row) {
            foreach ($row as $k => $v) {
                if (!is_integer($v)) continue;
                if (!array_key_exists($v, $data_by_ids)) continue;

                $rows[$key][$k] = $data_by_ids[$v];
            }
        }
        return $rows;
    }

    private function create_sideload_get($sideload_arr) {
        $temp = array();
        foreach ($sideload_arr as $field => $subfields) {
            if (is_array($subfields)) {
                foreach ($subfields as $subfield) {
                    $temp[] = $field . "." . $subfield;
                }
            }
            else
                $temp[] = $subfields;
        }
        return array("sideload" => $temp);
    }

    private function populate_sideload($data, $form, $sideload) {
        $rows = $data[$form];
        $data_by_ids = array();
        if (count($rows) < 1) return $rows;
        foreach ($sideload as $field => $subfields) {
            if(key_exists($this->pluralize($field), $data)){
                foreach ($data[$this->pluralize($field)] as $sidedata) {
                    $data_by_ids[$field][$sidedata['id']] = $sidedata;
                }
            }
        }

        foreach ($rows as $key => $row) {
            foreach ($sideload as $field => $subfields) {
                if(key_exists($field, $data_by_ids)){
                    if(key_exists($row[$field], $data_by_ids[$field])){
                        $rows[$key][$field] = $data_by_ids[$field][$row[$field]];
                    }
                }
            }
        }
        return $rows;
    }

    public function findRow($form, $id) {
        $back = $this->request("get", $this->pluralize_and_camelCase($form)."/".$id, "");
        if ($this->outputFormat == "raw")
            return $back;
        else
            return $back[$form];
    }

    public function insertRow($form, $values, $params = array()) {
        $values = array($this->camelCase($form) => $values);
        if (array_key_exists("validation", $values[$this->camelCase($form)]) && $values[$this->camelCase($form)]['validation'] == "off") {
            unset($values[$this->camelCase($form)]['validation']);
            $values['validation'] = "off";
        }
        $back = $this->request("post", $this->pluralize_and_camelCase($form), json_encode($values));
        return $back;
    }

    public function removeRow($form, $id) {
        $back = $this->request("delete", $this->pluralize_and_camelCase($form)."/".$id, "");
        return $back;
    }

    public function restoreRow($form, $id) {
        $back = $this->request("put", $this->pluralize_and_camelCase($form)."/".$id, json_encode(array($form => array("row_info.status" => "normal"))));
        return $back;
    }

    public function updateRow($form, $id, $values) {
        if (is_array($id)) {
            $values['id'] = $id;
            $url = $this->pluralize_and_camelCase($form);
        }
        else {
            $url = $this->pluralize_and_camelCase($form)."/".$id;
        }

        if (array_key_exists("validation", $values) && $values['validation'] == "off") {
            unset($values['validation']);
            $url .= "?validation=off";
        }

        $back = $this->request("put", $url, json_encode(array($this->camelCase($form) => $values)));

        return $back;
    }

    public function forms($form = null) {
        return $this->request("get", "forms".($form ? "/".$form : ""));
    }

    public function getMe($linkings = false) {
        $this->allow_error = true;
        $ret = $this->request("get", "me".($linkings ? "?linkings": ""));
        $this->allow_error = false;
        return $ret;
    }

    public function update() {
        $back = $this->request("post", "update");
        return $back;
    }

    public function request($method, $path, $content = null) {

        $url = $this->apiurl.$path;
        $ch = \curl_init();

        $method = strtolower($method);

        curl_setopt($ch, CURLOPT_URL,$url);

        if ($method == "post")
            curl_setopt($ch, CURLOPT_POST, 1);
        else if ($method == "put")
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        else if ($method == "delete")
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

        if ($method == "post" || $method == "put")
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);

        if ($this->access_token)
            $this->setHeader("Authorization", "Bearer ".$this->access_token);

        //$this->setHeader("Content-Type", "application/json");

        $headers = $this->formatHeaders($this->headers);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec ($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //print "output: ".$server_output;
        if ($errno = curl_errno($ch)) {
            return array("error" => $errno, "error_description" => curl_error($ch));
        }

        curl_close ($ch);

        if ($this->debug_mode) {
            print "Collector API returned ".$server_output." ($httpcode) for $method $url ".json_encode($content)." H: ".json_encode($headers);
        }

        if (!$this->allow_error && array_search($httpcode, $this->ok_http_codes) === false) {
            $path_start = explode("?", $path);
            throw new \Exception("Collector API returned ".$server_output." ($httpcode) for $method $url");// ".json_encode($content)." H: ".json_encode($headers));
        }

        try {
            $json = json_decode($server_output, true);
        }
        catch (Exception $e) {
            print $e->getMessage();
        }

        return $json === null ? $server_output : (array) $json;
    }

    public function union($dataset1, $dataset1_formname, $dataset2, $dataset2_formname, $destination_formname, $distinct = array()) {
        $sideloadCache = array();
        $temp = array();
        $distinctCache = array();

        $count = 0;

        foreach ($dataset1 as $key => $data) {
            if ($key == "count") continue;
            if ($key == $dataset1_formname) {
                $added_count = $this->unionAddToTemp($data,$destination_formname, $temp, $distinct, $distinctCache);
                $count += $added_count;
                continue;
            }

            $this->unionAddToSideloads($key, $data, $temp, $sideloadCache);
        }

        foreach ($dataset2 as $key => $data) {
            if ($key == "count") continue;
            if ($key == $dataset2_formname) {
                $added_count = $this->unionAddToTemp($data,$destination_formname, $temp, $distinct, $distinctCache);
                $count += $added_count;
                continue;
            }

            $this->unionAddToSideloads($key, $data, $temp, $sideloadCache);
        }

        $temp['count'] = $count;

        return $temp;
    }

    private function unionAddToTemp(&$data,$destination_formname, &$temp, $distinct, &$distinctCache) {
        $count = 0;
        foreach ($data as $row) {
            // use distinct filter
            if (count($distinct) > 0) {
                $distinct_key = "";
                foreach ($distinct as $d) $distinct_key .= '['.$row[$d].']';
                if (array_search($distinct_key, $distinctCache) !== false) continue;
                $distinctCache[] = $distinct_key;
            }

            $temp[$destination_formname][] = $row;
            $count++;
        }

        return $count;
    }

    private function unionAddToSideloads($key, &$data, &$temp, &$sideloadCache) {

        if (!array_key_exists($key, $sideloadCache)) $sideloadCache[$key] = array();

        foreach ($data as $row) {
            if (array_search($row['id'], $sideloadCache[$key]) !== false) continue;
            $temp[$key][] = $row;
            $sideloadCache[$key][] = $row['id'];
        }
    }

    public function camelCase($form) {
        $parts = explode("_", $form);
        if (count($parts) < 2) return $form;
        return $parts[0].ucfirst($parts[1]);
    }

    public function pluralize($name) {
        if (substr($name,strlen($name) - 1, 1) == "s") return $name;
        return $name."s";
    }

    public function pluralize_and_camelCase($name) {
        return $this->camelCase($this->pluralize($name));
    }

    private function formatHeaders($headers)
    {
        $ret = array();
        foreach($headers as $k => $v) {
            if ($v !== null) $ret[] = $k.": ".$v;
        }
        return $ret;
    }
}
