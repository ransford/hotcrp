<?php
// formula.php -- HotCRP helper class for paper expressions
// HotCRP is Copyright (c) 2009-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaCompileState {
    public $contact;
    public $gtmp = array();
    public $gstmt = array();
    public $lstmt = array();
    private $lprefix = 0;
    private $maxlprefix = 0;
    public $indent = 2;
    public $queryOptions = array();
    private $_stack = array();

    function __construct($contact) {
        $this->contact = $contact;
    }
    function _addgtemp($name, $expr) {
        if (($tname = @$this->gtmp[$name]) === null) {
            if (preg_match('/\A\w+\z/', $name))
                $tname = "\$$name";
            else
                $tname = "\$g" . count($this->gtmp);
            $this->gstmt[] = "$tname = $expr;";
            $this->gtmp[$name] = $tname;
        }
        return $tname;
    }
    function _push() {
        $this->_stack[] = array($this->lprefix, $this->lstmt);
        $this->lprefix = ++$this->maxlprefix;
        $this->lstmt = array();
        $this->indent += 2;
        return $this->lprefix;
    }
    function _pop($content) {
        list($this->lprefix, $this->lstmt) = array_pop($this->_stack);
        $this->indent -= 2;
        $this->lstmt[] = $content;
    }
    function _addltemp($expr = "null", $always_var = false) {
        if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr))
            return $expr;
        $tname = "\$t" . $this->lprefix . "_" . count($this->lstmt);
        $this->lstmt[] = "$tname = $expr;";
        return $tname;
    }
    function _add_submitted_reviewers() {
        if (!isset($this->gtmp["submitted_reviewers"])) {
            $this->gtmp["submitted_reviewers"] = "\$submitted_reviewers";
            $this->gstmt[] = "\$submitted_reviewers = (\$forceShow || \$contact->can_view_review(\$prow, null, false) ? \$prow->submitted_reviewers() : array());";
        }
        return "\$submitted_reviewers";
    }
    function _add_review_prefs() {
        if (!isset($this->gtmp["allrevprefs"])) {
            $this->gtmp["allrevprefs"] = "\$allrevprefs";
            $this->gstmt[] = "\$allrevprefs = (\$forceShow || \$contact->can_view_review(\$prow, null, false) ? \$prow->reviewer_preferences() : array());";
        }
        return "\$allrevprefs";
    }
}

class FormulaExpr {
    public $op;
    public $aggt;
    public $args = array();
    public $text;
    public $format = null;

    const AREV = 1;
    const APREF = 2;

    public function __construct($op, $aggt = 0) {
        $this->op = $op;
        $this->aggt = $aggt;
    }
    public function add($x) {
        if ($x instanceof FormulaExpr)
            $this->aggt |= $x->aggt;
        $this->args[] = $x;
    }
    static public function make($op) {
        $e = new FormulaExpr($op);
        $args = func_get_args();
        for ($i = 1; $i < count($args); ++$i)
            $e->add($args[$i]);
        return $e;
    }
    static public function make_aggt($op, $aggt /* , ... */) {
        $e = new FormulaExpr($op, $aggt);
        $args = func_get_args();
        for ($i = 2; $i < count($args); ++$i)
            $e->add($args[$i]);
        return $e;
    }
    public function set_format() {
        foreach ($this->args as $a)
            if ($a instanceof FormulaExpr)
                $a->set_format();
        if ($this->op === "revprefexp")
            $this->format = "revprefexp";
        else if ($this->op === "rf")
            $this->format = $this->args[0];
        else if (($this->op === "max" || $this->op === "min"
                  || $this->op === "avg" || $this->op === "wavg")
                 && count($this->args) >= 1
                 && $this->args[0] instanceof FormulaExpr)
            $this->format = $this->args[0]->format;
        else if ($this->op === "greatest" || $this->op === "least"
                 || $this->op === "?:") {
            $this->format = false;
            for ($i = ($this->op === "?:" ? 1 : 0); $i < count($this->args); ++$i) {
                $a = $this->args[$i];
                if ($a instanceof FormulaExpr
                    && ($this->format === false || $this->format === $a->format))
                    $this->format = $a->format;
                else
                    $this->format = null;
            }
            if ($this->format === false)
                $this->format = null;
        } else
            $this->format = null;
    }
    private function _resolve_using($e) {
        $word = $this->args[0];
        if (!($e instanceof FormulaExpr) || ($x = $e->resolve_scores()))
            return $word;
        $e->set_format();
        if ($e->format === "revprefexp" && $word >= "X" && $word <= "Z") {
            $this->op = "";
            $this->args[0] = 89 - ord($word);
            return false;
        } else if ($e->format instanceof ReviewField
                   && ($x = $e->format->parse_value($word, true))) {
            $this->op = "";
            $this->args[0] = $x;
            return false;
        } else
            return $word;
    }
    public function resolve_scores() {
        // comparison operators help us resolve
        if (preg_match(',\A(?:[<>=!]=?|≤|≥|≠)\z,', $this->op)
            && count($this->args) === 2) {
            list($a0, $a1) = $this->args;
            if ($a0 instanceof FormulaExpr && $a0->op === "??")
                $a0->_resolve_using($a1);
            if ($a1 instanceof FormulaExpr && $a1->op === "??")
                $a1->_resolve_using($a0);
        }

        if ($this->op === "??")
            return $this->args[0];
        foreach ($this->args as $a)
            if ($a instanceof FormulaExpr && ($x = $a->resolve_scores()))
                return $x;
        return false;
    }
}

class Formula {

    public $formulaId = null;
    public $name = null;
    public $heading = "";
    public $headingTitle = "";
    public $expression = null;
    public $authorView = null;
    public $createdBy = 0;
    public $timeModified = 0;

    private $_parse = null;
    private $_error_html = null;

    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|==?|!=|<[<=]?|>[>=]?|≤|≥|≠)/';

    private static $_opprec = array(
        "**" => 13,
        "u+" => 12, "u-" => 12, "u!" => 12,
        "*" => 11, "/" => 11, "%" => 11,
        "+" => 10, "-" => 10,
        "<<" => 9, ">>" => 9,
        "<" => 8, ">" => 8, "<=" => 8, ">=" => 8, "≤" => 8, "≥" => 8,
        "=" => 7, "==" => 7, "!=" => 7, "≠" => 7,
        "&" => 6,
        "^" => 5,
        "|" => 4,
        ":" => 3,
        "&&" => 2,
        "||" => 1,
        "?:" => 0
    );

    private static $_oprassoc = array(
        "**" => true
    );

    private static $_oprewrite = array(
        "=" => "==", ":" => "==", "≤" => "<=", "≥" => ">=", "≠" => "!="
    );


    public function __construct(/* $fexpr */) {
        $args = func_get_args();
        if (is_object(@$args[0])) {
            foreach ($args[0] as $k => $v)
                $this->$k = $v;
        } else if (is_string(@$args[0]))
            $this->expression = $args[0];
    }


    /* parsing */

    public function check() {
        if ($this->_parse !== null)
            return !!$this->_parse;

        $t = $this->expression;
        $e = $this->_parse_ternary($t, false);
        if ((string) $this->expression === "")
            $this->_error_html = "Empty formula.";
        else if ($t !== "" || !$e) {
            $prefix = substr($this->expression, 0, strlen($this->expression) - strlen($t));
            $this->_error_html = "Parse error in formula “" . htmlspecialchars($prefix) . "&nbsp;<span style='color:red'>&rarr;</span>&nbsp;" . htmlspecialchars(substr($this->expression, strlen($prefix))) . "”.";
        } else if ($e->aggt)
            $this->_error_html = "Illegal formula: can’t return a raw score, use an aggregate function.";
        else if (($x = $e->resolve_scores()))
            $this->_error_html = "Illegal formula: can’t resolve “" . htmlspecialchars($x) . "” to a score.";
        else {
            $e->text = $this->expression;
            $e->set_format();
        }
        $this->_parse = (count($this->_error_html) ? false : $e);
        if ($this->authorView === null) {
            if ($this->_parse) {
                global $Me;
                $this->authorView = $this->view_score($Me);
            } else
                $this->authorView = VIEWSCORE_FALSE;
        }
        return !!$this->_parse;
    }

    public function error_html() {
        $this->check();
        return $this->_error_html;
    }

    private function _parse_ternary(&$t, $in_qc) {
        $e = $this->_parse_expr($t, 0, $in_qc);
        if (!$e || ($t = ltrim($t)) === "" || $t[0] !== "?")
            return $e;
        $t = substr($t, 1);
        if (($e1 = $this->_parse_ternary($t, true)) !== null)
            if (($t = ltrim($t)) !== "" && $t[0] === ":") {
                $t = substr($t, 1);
                if (($e2 = $this->_parse_ternary($t, $in_qc)))
                    return FormulaExpr::make("?:", $e, $e1, $e2);
            }
        return null;
    }

    private function _parse_function($op, &$t, $is_aggregate) {
        $t = ltrim($t);
        $e = FormulaExpr::make($op);

        // collect arguments
        if ($t !== "" && $t[0] === "(") {
            while (1) {
                $t = substr($t, 1);
                if (!($e2 = $this->_parse_ternary($t, false)))
                    return null;
                $e->add($e2);
                $t = ltrim($t);
                if ($t !== "" && $t[0] === ")")
                    break;
                else if ($t === "" || $t[0] !== ",")
                    return null;
            }
            $t = substr($t, 1);
        } else if (($e2 = $this->_parse_expr($t, self::$_opprec["u+"], false)))
            $e->add($e2);
        else
            return null;

        // maybe clear aggregateness
        if ($is_aggregate && count($e->args) <= ($e->op === "wavg" ? 2 : 1))
            $e->aggt = 0;
        return $e;
    }

    private function _parse_expr(&$t, $level, $in_qc) {
        if (($t = ltrim($t)) === "")
            return null;

        if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = $this->_parse_ternary($t, false);
            $t = ltrim($t);
            if (!$e || $t[0] !== ")")
                return null;
            $t = substr($t, 1);
        } else if ($t[0] === "-" || $t[0] === "+" || $t[0] === "!") {
            $op = $t[0];
            $t = substr($t, 1);
            if (!($e = $this->_parse_expr($t, self::$_opprec["u$op"], $in_qc)))
                return null;
            $e = FormulaExpr::make($op, $e);
        } else if (preg_match('/\Anot([\s(].*|)\z/i', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_expr($t, self::$_opprec["u!"], $in_qc)))
                return null;
            $e = FormulaExpr::make("!", $e);
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", $m[1] + 0.0);
            $t = $m[2];
        } else if (preg_match('/\A(false|true)\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(?:tag(?:\s*:\s*|\s+)|#)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = FormulaExpr::make("tag", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\Atag(?:v|-?val|-?value)(?:\s*:\s*|\s+)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag(?:v|-?val|-?value)\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = FormulaExpr::make("tagval", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(all|any|avg|count|min|max|std(?:dev(?:_pop|_samp)?)?|sum|var(?:iance|_pop|_samp)?|wavg)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_function($m[1], $t, true)))
                return null;
        } else if (preg_match('/\A(greatest|least)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_function($m[1], $t, false)))
                return null;
        } else if (preg_match('/\Anull\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", "null");
            $t = $m[1];
        } else if (preg_match('/\A(ispri(?:mary)?|issec(?:ondary)?|(?:is)?ext(?:ernal)?)\b(.*)\z/s', $t, $m)) {
            if ($m[1] == "ispri" || $m[1] == "isprimary")
                $rt = REVIEW_PRIMARY;
            else if ($m[1] == "issec" || $m[1] == "issecondary")
                $rt = REVIEW_SECONDARY;
            else if ($m[1] == "ext" || $m[1] == "external"
                     || $m[1] == "isext" || $m[1] == "isexternal")
                $rt = REVIEW_EXTERNAL;
            $e = FormulaExpr::make_aggt("revtype", FormulaExpr::AREV, $rt);
            $t = $m[2];
        } else if (preg_match('/\A(?:rev)?pref\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_aggt("revpref", FormulaExpr::APREF);
            $t = $m[1];
        } else if (preg_match('/\A(?:rev)?prefexp(?:ertise)?\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_aggt("revprefexp", FormulaExpr::APREF);
            $t = $m[1];
        } else if (preg_match('/\A([a-zA-Z0-9_]+|\".*?\")(.*)\z/s', $t, $m)
                   && $m[1] !== "\"\"") {
            $field = $m[1];
            $t = $m[2];
            if (($quoted = $field[0] === "\""))
                $field = substr($field, 1, strlen($field) - 2);
            $rf = reviewForm();
            if (($fid = $rf->unabbreviateField($field)))
                $e = FormulaExpr::make_aggt("rf", FormulaExpr::AREV, $rf->field($fid));
            else if (!$quoted && strlen($field) === 1 && ctype_alpha($field))
                $e = FormulaExpr::make_aggt("??", 0, strtoupper($field));
            else
                return null;
        } else
            return null;

        while (1) {
            if (($t = ltrim($t)) === "")
                return $e;
            else if (preg_match(self::BINARY_OPERATOR_REGEX, $t, $m)) {
                $op = $m[0];
                $tn = substr($t, strlen($m[0]));
            } else if (preg_match('/\A(and|or)([\s(].*|)\z/i', $t, $m)) {
                $op = strlen($m[1]) == 3 ? "&&" : "||";
                $tn = $m[2];
            } else if (!$in_qc && substr($t, 0, 1) === ":") {
                $op = ":";
                $tn = substr($t, 1);
            } else
                return $e;

            $opprec = self::$_opprec[$op];
            if ($opprec < $level)
                return $e;

            $t = $tn;
            $op = @self::$_oprewrite[$op] ? : $op;
            if (!($e2 = $this->_parse_expr($t, @self::$_oprassoc[$op] ? $opprec : $opprec + 1, $in_qc)))
                return null;
            $e = FormulaExpr::make($op, $e, $e2);
        }
    }


    /* compilation */

    private static function _compile_loop($state, $initial_value, $combiner, $e) {
        $t_result = $state->_addltemp($initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);
        $p = $state->_push();

        $aggt = 0;
        foreach ($e->args as $i => $ee) {
            $t = $state->_addltemp(self::_compile($state, $ee));
            $combiner = str_replace("~l" . ($i ? : "") . "~", $t, $combiner);
            $aggt |= $ee->aggt;
        }
        $state->lstmt[] = "$t_result = $combiner;";

        $t_looper = "\$i$p";
        $indent = "\n" . str_pad("", $state->indent);
        if ($aggt === (FormulaExpr::AREV | FormulaExpr::APREF)) {
            $g = $state->_addgtemp("rev_and_pref_cids", $state->_add_review_prefs() . " + array_flip(" . $state->_add_submitted_reviewers() . ")");
            $loop = "foreach ($g as \$i$p => \$v$p)";
        } else if ($aggt === FormulaExpr::AREV)
            $loop = "foreach (" . $state->_add_submitted_reviewers() . " as \$i$p)";
        else
            $loop = "foreach (" . $state->_add_review_prefs() . " as \$i$p => \$v$p)";
        $loop .= " {" . $indent . join($indent, $state->lstmt) . "\n" . str_pad("", $state->indent - 2) . "}";
        if ($aggt == FormulaExpr::APREF)
            $loop = str_replace("\$allrevprefs[~i~]", "\$v$p", $loop);
        $loop = str_replace("~i~", "\$i$p", $loop);

        $state->_pop($loop);
        return $t_result;
    }

    private static function _cast_bool($t) {
        return "($t !== null ? (bool) $t : null)";
    }

    private static function _compile($state, $e) {
        $op = $e->op;
        if ($op == "")
            return $e->args[0];

        if ($op == "tag" || $op == "tagval") {
            $state->queryOptions["tags"] = true;
            $tagger = new Tagger($state->contact);
            $e_tag = $tagger->check($e->args[0]);
            $t_tags = $state->_addgtemp("tags", "(\$forceShow || \$contact->can_view_tags(\$prow, true) ? \$prow->paperTags : '')");
            $t_tagpos = $state->_addgtemp("tagpos {$e->args[0]}", "stripos($t_tags, \" $e_tag#\")");
            $t_tagval = $state->_addgtemp("tagval {$e->args[0]}", "($t_tagpos !== false ? (int) substr($t_tags, $t_tagpos + " . (strlen($e_tag) + 2) . ") : null)");
            if ($op == "tag")
                return "($t_tagval !== 0 ? $t_tagval : true)";
            else
                return $t_tagval;
        }

        if ($op == "revtype") {
            $view_score = $state->contact->viewReviewFieldsScore(null, true);
            if (VIEWSCORE_PC <= $view_score)
                $t_f = "null";
            else {
                $state->queryOptions["reviewTypes"] = true;
                $t_f = "(" . $state->_addgtemp("revtypes", "explode(',', \$prow->reviewTypes)") . "[~i~]==" . $e->args[0] . ")";
            }
            return $t_f;
        }

        if ($op == "rf") {
            $f = $e->args[0];
            $view_score = $state->contact->viewReviewFieldsScore(null, true);
            if ($f->view_score <= $view_score)
                return "null";
            if (!isset($state->queryOptions["scores"]))
                $state->queryOptions["scores"] = array();
            $state->queryOptions["scores"][$f->id] = true;
            $t_f = $state->_addgtemp($f->id, "\$prow->scores(\"{$f->id}\")") . "[~i~]";
            return "((int) @$t_f ? : null)";
        }

        if ($op == "revpref" || $op == "revprefexp") {
            $view_score = $state->contact->viewReviewFieldsScore(null, true);
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["allReviewerPreference"] = true;
            return "@" . $state->_add_review_prefs() . "[~i~][" . ($op == "revpref" ? 0 : 1) . "]";
        }

        if ($op == "?:") {
            $t = $state->_addltemp(self::_compile($state, $e->args[0]));
            $tt = $state->_addltemp(self::_compile($state, $e->args[1]));
            $tf = $state->_addltemp(self::_compile($state, $e->args[2]));
            return "($t ? $tt : $tf)";
        }

        if (count($e->args) == 1 && isset(self::$_opprec["u$op"])) {
            $t = $state->_addltemp(self::_compile($state, $e->args[0]));
            if ($op == "!")
                return "$op$t";
            else
                return "($t === null ? $t : $op$t)";
        }

        if (count($e->args) == 2 && isset(self::$_opprec[$op])) {
            $t1 = $state->_addltemp(self::_compile($state, $e->args[0]));
            $t2 = $state->_addltemp(self::_compile($state, $e->args[1]));
            if ($op == "&&")
                return "($t1 ? $t2 : $t1)";
            else if ($op == "||")
                return "($t1 ? : $t2)";
            else
                return "($t1 !== null && $t2 !== null ? $t1 $op $t2 : null)";
        }

        if (count($e->args) == 1 && $op == "all") {
            $t = self::_compile_loop($state, "null", "(~r~ !== null ? ~l~ && ~r~ : ~l~)", $e);
            return self::_cast_bool($t);
        }

        if (count($e->args) == 1 && $op == "any") {
            $t = self::_compile_loop($state, "null", "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)", $e);
            return self::_cast_bool($t);
        }

        if (count($e->args) == 1 && $op == "min")
            return self::_compile_loop($state, "null", "(~l~ !== null && (~r~ === null || ~l~ < ~r~) ? ~l~ : ~r~)", $e);

        if (count($e->args) == 1 && $op == "max")
            return self::_compile_loop($state, "null", "(~l~ !== null && (~r~ === null || ~l~ > ~r~) ? ~l~ : ~r~)", $e);

        if (count($e->args) == 1 && $op == "count")
            return self::_compile_loop($state, "0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)", $e);

        if (count($e->args) == 1 && $op == "sum")
            return self::_compile_loop($state, "null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)", $e);

        if (count($e->args) == 1 && ($op == "avg" || $op == "wavg")) {
            $t = self::_compile_loop($state, "array(0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~, ~r~[1] + 1) : ~r~)", $e);
            return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
        }

        if (count($e->args) == 2 && $op == "wavg") {
            $t = self::_compile_loop($state, "array(0, 0)", "(~l~ !== null && ~l1~ !== null ? array(~r~[0] + ~l~ * ~l1~, ~r~[1] + ~l1~) : ~r~)", $e);
            return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
        }

        if (count($e->args) == 1 && ($op == "variance" || $op == "var" || $op == "var_pop" || $op == "var_samp" || $op == "std" || $op == "stddev" || $op == "stddev_pop" || $op == "stddev_samp")) {
            $t = self::_compile_loop($state, "array(0, 0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1) : ~r~)", $e);
            if ($op == "variance" || $op == "var" || $op == "var_samp")
                return "(${t}[2] > 1 ? ${t}[0] / (${t}[2] - 1) - (${t}[1] * ${t}[1]) / (${t}[2] * (${t}[2] - 1)) : (${t}[2] ? 0 : null))";
            else if ($op == "var_pop")
                return "(${t}[2] ? ${t}[0] / ${t}[2] - (${t}[1] * ${t}[1]) / (${t}[2] * ${t}[2]) : null)";
            else if ($op == "std" || $op == "stddev" || $op == "stddev_samp")
                return "(${t}[2] > 1 ? sqrt(${t}[0] / (${t}[2] - 1) - (${t}[1] * ${t}[1]) / (${t}[2] * (${t}[2] - 1))) : (${t}[2] ? 0 : null))";
            else if ($op == "stddev_pop")
                return "(${t}[2] ? sqrt(${t}[0] / ${t}[2] - (${t}[1] * ${t}[1]) / (${t}[2] * ${t}[2])) : null)";
        }

        return "null";
    }

    public function compile_function_body($contact) {
        global $Conf;
        $this->check();
        $state = new FormulaCompileState($contact);
        $expr = self::_compile($state, $this->_parse);

        $t = join("\n  ", $state->gstmt)
            . (count($state->gstmt) && count($state->lstmt) ? "\n  " : "")
            . join("\n  ", $state->lstmt) . "\n"
            . "  \$x = $expr;\n\n"
            . '  if ($format == "h") {
    if ($x === null || $x === false)
      return "";
    else if ($x === true)
      return "&#x2713;";
    else';

        // HTML format for output depends on type of output
        if ($this->_parse->format === "revprefexp")
            $t .= "\n      "
                . 'return ReviewField::unparse_letter(91, $x + 2);';
        else if ($this->_parse->format instanceof ReviewField
                 && $this->_parse->format->option_letter)
            $t .= "\n      "
                . 'return ReviewField::unparse_letter(' . $this->_parse->format->option_letter . ', $x);';
        else
            $t .= "\n      "
                . 'return round($x * 100) / 100;';

        $t .= "\n" . '  } else if ($format == "s")
    return ($x === true ? 1 : $x);
  else
    return $x;' . "\n";
        //$Conf->infoMsg(Ht::pre_text("function (\$prow, \$contact, \$format = null, \$forceShow = false) {\n  /* $this->expression */\n  $t}\n"));
        return $t;
    }

    public function compile_function($contact) {
        return create_function("\$prow, \$contact, \$format = null, \$forceShow = false", $this->compile_function_body($contact));
    }

    public function add_query_options(&$queryOptions, $contact) {
        $this->check();
        $state = new FormulaCompileState($contact);
        $state->queryOptions =& $queryOptions;
        self::_compile($state, $this->_parse);
    }

    private static function expression_view_score($e, $contact) {
        $op = $e->op;
        if ($op == "")
            return VIEWSCORE_AUTHOR;

        if ($op == "tag" || $op == "tagval") {
            $tagger = new Tagger($contact);
            $e_tag = $tagger->check($e->args[0]);
            return $tagger->view_score($e_tag, $contact);
        }

        if ($op == "rf")
            return $e->args[0]->view_score;

        if ($op == "revtype" || $op == "revpref" || $op == "revprefexp")
            return VIEWSCORE_PC;

        if ($op == "?:") {
            $t = self::expression_view_score($e->args[0], $contact);
            $tt = self::expression_view_score($e->args[1], $contact);
            $tf = self::expression_view_score($e->args[2], $contact);
            return min($t, max($tt, $tf));
        }

        $score = 1;
        for ($i = 0; $i < count($e->args); ++$i)
            $score = min($score, self::expression_view_score($e->args[$i], $contact));
        return $score;
    }

    public function base_view_score() {
        if ($this->authorView === null)
            $this->check();
        return $this->authorView;
    }

    public function view_score($contact) {
        $this->check();
        return self::expression_view_score($this->_parse, $contact);
    }

    public function column_header() {
        return $this->heading ? : ($this->name ? : $this->expression);
    }

}
