<?php
// ht.php -- HotCRP HTML helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Ht {

    public static $img_base = "";
    private static $_controlid = 0;
    private static $_lastcontrolid = 0;
    private static $_stash = "";
    private static $_stash_inscript = false;
    private static $_stash_map = array();
    private static $_bad_js = array("accept-charset" => true,
                                    "action" => true,
                                    "enctype" => true,
                                    "method" => true,
                                    "name" => true,
                                    "optionstyles" => true,
                                    "type" => true,
                                    "value" => true);

    static function extra($js) {
        $x = "";
        if ($js) {
            foreach ($js as $k => $v)
                if (!@self::$_bad_js[$k] && $k !== "disabled"
                    && $v !== null && $v !== false)
                    $x .= " $k=\"" . str_replace("\"", "'", $v) . "\"";
            if (@$js["disabled"])
                $x .= " disabled=\"disabled\"";
        }
        return $x;
    }

    static function script_file($src) {
        return "<script src=\"" . htmlspecialchars($src) . "\"></script>";
    }

    static function stylesheet_file($src) {
        return "<link rel=\"stylesheet\" type=\"text/css\" href=\""
            . htmlspecialchars($src) . "\" />";
    }

    static function form($action, $extra = null) {
        $method = @$extra["method"] ? : "post";
        if ($method === "get" && strpos($action, "?") !== false)
            error_log(caller_landmark() . ": GET form action $action params will be ignored");
        $enctype = @$extra["enctype"];
        if (!$enctype && $method !== "get")
            $enctype = "multipart/form-data";
        $x = '<form method="' . $method . '" action="' . $action . '"';
        if ($enctype)
            $x .= ' enctype="' . $enctype . '"';
        return $x . ' accept-charset="UTF-8"' . self::extra($extra) . '>';
    }

    static function form_div($action, $extra = null) {
        $div = "<div>";
        if (($divclass = @$extra["divclass"]))
            $div = '<div class="' . $divclass . '">';
        if (@$extra["method"] === "get" && ($qpos = strpos($action, "?")) !== false) {
            if (($hpos = strpos($action, "#", $qpos + 1)) === false)
                $hpos = strlen($action);
            foreach (preg_split('/(?:&amp;|&)/', substr($action, $qpos + 1, $hpos - $qpos - 1)) as $m)
                if (($eqpos = strpos($m, "=")) !== false)
                    $div .= '<input type="hidden" name="' . substr($m, 0, $eqpos) . '" value="' . urldecode(substr($m, $eqpos + 1)) . '" />';
            $action = substr($action, 0, $qpos) . substr($action, $hpos);
        }
        return self::form($action, $extra) . $div;
    }

    static function hidden($name, $value = "", $extra = null) {
        return '<input type="hidden" name="' . htmlspecialchars($name)
            . '" value="' . htmlspecialchars($value) . '"'
            . self::extra($extra) . ' />';
    }

    static function select($name, $opt, $selected = null, $js = null) {
        if (is_array($selected) && $js === null)
            list($js, $selected) = array($selected, null);
        $disabled = @$js["disabled"];
        if (is_array($disabled))
            unset($js["disabled"]);
        $x = '<select name="' . $name . '"' . self::extra($js) . ">";
        if ($selected === null || !isset($opt[$selected]))
            $selected = key($opt);
        $optionstyles = defval($js, "optionstyles", null);
        $optgroup = "";
        foreach ($opt as $value => $info) {
            if (is_array($info) && $info[0] == "optgroup")
                $info = (object) array("type" => "optgroup", "label" => $info[1]);
            else if (is_string($info)) {
                $info = (object) array("label" => $info);
                if (is_array($disabled) && isset($disabled[$value]))
                    $info->disabled = $disabled[$value];
                if ($optionstyles && isset($optionstyles[$value]))
                    $info->style = $optionstyles[$value];
            }

            if ($info === null)
                $x .= '<option disabled="disabled"></option>';
            else if (isset($info->type) && $info->type == "optgroup") {
                $x .= $optgroup . '<optgroup label="' . htmlspecialchars($info->label) . '">';
                $optgroup = "</optgroup>";
            } else {
                $x .= '<option value="' . $value . '"';
                if (strcmp($value, $selected) == 0)
                    $x .= ' selected="selected"';
                if (@$info->disabled)
                    $x .= ' disabled="disabled"';
                if (@$info->style)
                    $x .= ' style="' . $info->style . '"';
                if (@$info->id)
                    $x .= ' id="' . $info->id . '"';
                $x .= '>' . $info->label . '</option>';
            }
        }
        return $x . $optgroup . "</select>";
    }

    static function checkbox($name, $value = 1, $checked = false, $js = null) {
        if (is_array($value)) {
            $js = $value;
            $value = 1;
        } else if (is_array($checked)) {
            $js = $checked;
            $checked = false;
        }
        $js = $js ? $js : array();
        if (!defval($js, "id"))
            $js["id"] = "htctl" . ++self::$_controlid;
        self::$_lastcontrolid = $js["id"];
        if (!isset($js["class"]))
            $js["class"] = "cb";
        $t = '<input type="checkbox"'; /* NB see Ht::radio */
        if ($name)
            $t .= " name=\"$name\" value=\"" . htmlspecialchars($value) . "\"";
        if ($checked === null)
            $checked = isset($_REQUEST[$name]) && $_REQUEST[$name] == $value;
        if ($checked)
            $t .= " checked=\"checked\"";
        return $t . self::extra($js) . " />";
    }

    static function radio($name, $value = 1, $checked = false, $js = null) {
        $t = self::checkbox($name, $value, $checked, $js);
        return '<input type="radio"' . substr($t, 22);
    }

    static function checkbox_h($name, $value = 1, $checked = false, $js = null) {
        $js = $js ? $js : array();
        if (!isset($js["onchange"]))
            $js["onchange"] = "hiliter(this)";
        return self::checkbox($name, $value, $checked, $js);
    }

    static function radio_h($name, $value = 1, $checked = false, $js = null) {
        $t = self::checkbox_h($name, $value, $checked, $js);
        return '<input type="radio"' . substr($t, 22);
    }

    static function label($html, $id = null) {
        if (!$id || $id === true)
            $id = self::$_lastcontrolid;
        return '<label for="' . $id . '">' . $html . "</label>";
    }

    static function button($name, $html, $js = null) {
        if (!$js && is_array($html)) {
            $js = $html;
            $html = null;
        } else if (!$js)
            $js = array();
        if (!isset($js["class"]))
            $js["class"] = "b";
        $type = isset($js["type"]) ? $js["type"] : "button";
        if ($name && !$html) {
            $html = $name;
            $name = "";
        } else
            $name = $name ? " name=\"$name\"" : "";
        if ($type == "button" || preg_match("_[<>]_", $html) || isset($js["value"]))
            return "<button type=\"$type\"$name value=\""
                . defval($js, "value", 1) . "\"" . self::extra($js)
                . ">" . $html . "</button>";
        else
            return "<input type=\"$type\"$name value=\"$html\""
                . self::extra($js) . " />";
    }

    static function submit($name, $html = null, $js = null) {
        if (!$js && is_array($html)) {
            $js = $html;
            $html = null;
        } else if (!$js)
            $js = array();
        $js["type"] = "submit";
        return self::button($html ? $name : "", $html ? : $name, $js);
    }

    static function js_button($html, $onclick, $js = null) {
        if (!$js && is_array($onclick)) {
            $js = $onclick;
            $onclick = null;
        } else if (!$js)
            $js = array();
        if ($onclick)
            $js["onclick"] = $onclick;
        return self::button("", $html, $js);
    }

    static function hidden_default_submit($name, $text = null, $js = null) {
        if (!$js && is_array($text)) {
            $js = $text;
            $text = null;
        } else if (!$js)
            $js = array();
        $js["class"] = trim(defval($js, "class", "") . " hidden");
        return self::submit($name, $text, $js);
    }

    static function entry($name, $value, $js = null) {
        $js = $js ? $js : array();
        if (($temp = @$js["hottemptext"])) {
            if ($value === null || $value === "" || $value === $temp)
                $js["class"] = trim(defval($js, "class", "") . " temptext");
            if ($value === null || $value === "")
                $value = $temp;
            $temp = ' hottemptext="' . htmlspecialchars($temp) . '"';
            self::stash_script("hotcrp_load(hotcrp_load.temptext)", "temptext");
        } else
            $temp = "";
        unset($js["hottemptext"]);
        $type = @$js["type"] ? : "text";
        return '<input type="' . $type . '" name="' . $name . '" value="'
            . htmlspecialchars($value === null ? "" : $value) . '"'
            . self::extra($js) . $temp . ' />';
    }

    static function entry_h($name, $value, $js = null) {
        $js = $js ? $js : array();
        if (!isset($js["onchange"]))
            $js["onchange"] = "hiliter(this)";
        return self::entry($name, $value, $js);
    }

    static function password($name, $value, $js = null) {
        $js = $js ? $js : array();
        $js["type"] = "password";
        return self::entry($name, $value, $js);
    }

    static function textarea($name, $value, $js = null) {
        $js = $js ? $js : array();
        return '<textarea name="' . $name . '"' . self::extra($js)
            . '>' . htmlspecialchars($value === null ? "" : $value)
            . '</textarea>';
    }

    static function actions($actions, $js = array(), $extra_text = "") {
        if (!count($actions))
            return "";
        $js = $js ? : array();
        if (!isset($js["class"]))
            $js["class"] = "aa";
        $t = "<div" . self::extra($js) . ">";
        if ($js["class"] === "aab") {
            foreach ($actions as $a) {
                $t .= '<div class="aabut">';
                if (is_array($a)) {
                    $t .= $a[0];
                    if (count($a) > 1)
                        $t .= '<br><span class="hint">' . $a[1] . '</span>';
                } else
                    $t .= $a;
                $t .= '</div>';
            }
            $t .= '<hr class="c">';
        } else if (count($actions) > 1 || is_array($actions[0])) {
            $t .= "<table class=\"pt_buttons\"><tr>";
            $explains = 0;
            foreach ($actions as $a) {
                $t .= "<td class=\"ptb_button\">";
                if (is_array($a)) {
                    $t .= $a[0];
                    $explains += count($a) > 1;
                } else
                    $t .= $a;
                $t .= "</td>";
            }
            $t .= "</tr>";
            if ($explains) {
                $t .= "<tr>";
                foreach ($actions as $a) {
                    $t .= "<td class=\"ptb_explain\">";
                    if (is_array($a) && count($a) > 1)
                        $t .= $a[1];
                    $t .= "</td>";
                }
                $t .= "</tr>";
            }
            $t .= "</table>";
        } else
            $t .= $actions[0];
        return $t . $extra_text . "</div>\n";
    }

    static function pre($html) {
        if (is_array($html))
            $text = join("\n", $html);
        return "<pre>" . $html . "</pre>";
    }

    static function pre_text($text) {
        if (is_array($text)
            && array_keys($text) === range(0, count($text) - 1))
            $text = join("\n", $text);
        else if (is_array($text) || is_object($text))
            $text = var_export($text, true);
        return "<pre>" . htmlspecialchars($text) . "</pre>";
    }

    static function pre_text_wrap($text) {
        if (is_array($text))
            $text = join("\n", $text);
        else if (is_object($text))
            $text = var_export($text, true);
        return "<pre style=\"white-space: pre-wrap\">" . htmlspecialchars($text) . "</pre>";
    }

    static function pre_export($x) {
        return "<pre>" . htmlspecialchars(var_export($x, true)) . "</pre>";
    }

    static function pre_export_wrap($x) {
        return "<pre style=\"white-space: pre-wrap\">" . htmlspecialchars(var_export($x, true)) . "</pre>";
    }

    static function img($src, $alt, $js = null) {
        if (is_string($js))
            $js = array("class" => $js);
        if (self::$img_base && !preg_match(',\A(?:https?:/|/),i', $src))
            $src = self::$img_base . $src;
        return "<img src=\"" . $src . "\" alt=\"" . htmlspecialchars($alt) . "\""
            . self::extra($js) . " />";
    }

    static function popup($idpart, $content, $form = null, $actions = null) {
        if ($form && $actions)
            $form .= "<div class=\"popup_actions\">" . $actions . "</div></form>";
        self::stash_html("<div id=\"popup_$idpart\" class=\"popupc\">"
                         . $content . ($form ? $form : "") . "</div>");
    }

    static function mark_stash($uniqueid) {
        $marked = @self::$_stash_map[$uniqueid];
        self::$_stash_map[$uniqueid] = true;
        return !$marked;
    }

    static function stash_html($html, $uniqueid = null) {
        if ($html !== null && $html !== false && $html !== ""
            && (!$uniqueid || self::mark_stash($uniqueid))) {
            if (self::$_stash_inscript)
                self::$_stash .= "</script>";
            self::$_stash .= $html;
            self::$_stash_inscript = false;
        }
    }

    static function stash_script($js, $uniqueid = null) {
        if ($js !== null && $js !== false && $js !== ""
            && (!$uniqueid || self::mark_stash($uniqueid))) {
            if (!self::$_stash_inscript)
                self::$_stash .= "<script>";
            else if (($c = self::$_stash[strlen(self::$_stash) - 1]) !== "}"
                     && $c !== "{" && $c !== ";")
                self::$_stash .= ";";
            self::$_stash .= $js;
            self::$_stash_inscript = true;
        }
    }

    static function take_stash() {
        $stash = self::$_stash;
        if (self::$_stash_inscript)
            $stash .= "</script>";
        self::$_stash = "";
        self::$_stash_inscript = false;
        return $stash;
    }

}
