<?php
// users.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/contactlist.php");
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];


// list type
$tOpt = array();
$tOpt["pc"] = "Program committee";
if ($Me->isPC && count($pctags = pcTags())) {
    foreach ($pctags as $t)
        if ($t != "pc")
            $tOpt["pc:$t"] = "#$t program committee";
}
if ($Me->isPC)
    $tOpt["admin"] = "System administrators";
if ($Me->privChair || ($Me->isPC && $Conf->timePCViewAllReviews())) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
    $tOpt["extsub"] = "External reviewers who completed a review";
}
if ($Me->isPC)
    $tOpt["req"] = "External reviewers you requested";
if ($Me->privChair || ($Me->isPC && $Conf->subBlindNever()))
    $tOpt["au"] = "Contact authors of submitted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->timePCViewDecision(true)))
    $tOpt["auacc"] = "Contact authors of accepted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->subBlindNever() && $Conf->timePCViewDecision(true)))
    $tOpt["aurej"] = "Contact authors of rejected papers";
if ($Me->privChair) {
    $tOpt["auuns"] = "Contact authors of non-submitted papers";
    $tOpt["all"] = "All users";
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren’t allowed to list those users.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);


// paper selection and download actions
function paperselPredicate($papersel) {
    return "ContactInfo.contactId" . sql_in_numeric_set($papersel);
}

if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if ((isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"]))
    || ($getaction && !isset($_REQUEST["pap"]))) {
    $allowed_papers = array();
    $pl = new ContactList($Me, true);
    // Ensure that we only select contacts we're allowed to see.
    if (($rows = $pl->rows($_REQUEST["t"]))) {
        foreach ($rows as $row)
            $allowed_papers[$row->paperId] = true;
    }
    $papersel = array();
    if (isset($_REQUEST["pap"])) {
        foreach ($_REQUEST["pap"] as $p)
            if (($p = cvtint($p)) > 0 && isset($allowed_papers[$p]))
                $papersel[] = $p;
    } else
        $papersel = array_keys($allowed_papers);
    if (count($papersel) == 0)
        unset($papersel);
}

if ($getaction == "nameemail" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe("select firstName first, lastName last, email, affiliation from ContactInfo where " . paperselPredicate($papersel) . " order by lastName, firstName, email");
    $people = edb_orows($result);
    downloadCSV($people, array("first", "last", "email", "affiliation"), "users",
                array("selection" => true));
    exit;
}

if ($getaction == "address" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe("select firstName first, lastName last, email, affiliation,
        voicePhoneNumber phone,
        addressLine1 address1, addressLine2 address2, city, state, zipCode zip, country
        from ContactInfo
        left join ContactAddress using (contactId)
        where " . paperselPredicate($papersel) . " order by lastName, firstName, email");
    $people = edb_orows($result);
    $phone = false;
    foreach ($people as $p)
        $phone = $phone || $p->phone;
    $header = array("first", "last", "email", "address1", "address2",
                    "city", "state", "zip", "country");
    if ($phone)
        $header[] = "phone";
    downloadCSV($people, $header, "addresses", array("selection" => true));
    exit;
}

function urlencode_matches($m) {
    return urlencode($m[0]);
}

if ($getaction == "pcinfo" && isset($papersel) && $Me->privChair) {
    assert($Conf->sversion >= 73);
    $result = $Conf->qe("select firstName first, lastName last, email,
        preferredEmail preferred_email, affiliation,
        voicePhoneNumber phone,
        addressLine1 address1, addressLine2 address2, city, state, zipCode zip, country,
        collaborators, defaultWatch, roles, disabled, contactTags tags, data,
        group_concat(concat(topicId,':'," . $Conf->query_topic_interest() . ")) topic_interest
        from ContactInfo
        left join ContactAddress on (ContactAddress.contactId=ContactInfo.contactId)
        left join TopicInterest on (TopicInterest.contactId=ContactInfo.contactId and TopicInterest.interest is not null)
        where " . paperselPredicate($papersel) . "
        group by ContactInfo.contactId order by lastName, firstName, email");

    // NB This format is expected to be parsed by profile.php's bulk upload.
    $topics = $Conf->topic_map();
    $people = array();
    $has = (object) array("topics" => array());
    while (($row = edb_orow($result))) {
        if ($row->phone)
            $has->phone = true;
        if ($row->preferred_email && $row->preferred_email != $row->email)
            $has->preferred_email = true;
        if ($row->disabled)
            $has->disabled = true;
        if ($row->tags && ($row->tags = trim($row->tags)))
            $has->tags = true;
        if ($row->address1 || $row->address2 || $row->city || $row->state || $row->zip || $row->country)
            $has->address = true;
        if ($row->topic_interest
            && preg_match_all('|(\d+):(-?\d+)|', $row->topic_interest, $m, PREG_SET_ORDER)) {
            foreach ($m as $x)
                if (($tn = @$topics[$x[1]]) && $x[2]) {
                    $k = "ti$x[1]";
                    $row->$k = (int) $x[2];
                    @($has->topics[$x[1]] = true);
                }
        }
        $row->follow = array();
        if ($row->defaultWatch & WATCH_COMMENT)
            $row->follow[] = "reviews";
        if (($row->defaultWatch & WATCH_ALLCOMMENTS)
            && ($row->roles & Contact::ROLE_PCLIKE))
            $row->follow[] = "allreviews";
        if (($row->defaultWatch & (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALL))
            && ($row->roles & (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)))
            $row->follow[] = "allfinal";
        $row->follow = join(",", $row->follow);
        if ($row->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) {
            $r = array();
            if ($row->roles & Contact::ROLE_CHAIR)
                $r[] = "chair";
            if ($row->roles & Contact::ROLE_PC)
                $r[] = "pc";
            if ($row->roles & Contact::ROLE_ADMIN)
                $r[] = "sysadmin";
            $row->roles = join(",", $r);
        } else
            $row->roles = "";
        $people[] = $row;
    }

    $header = array("first", "last", "email");
    if (@$has->preferred_email)
        $header[] = "preferred_email";
    $header[] = "roles";
    if (@$has->tags)
        $header[] = "tags";
    array_push($header, "affiliation", "collaborators", "follow");
    if (@$has->phone)
        $header[] = "phone";
    if (@$has->address)
        array_push($header, "address1", "address2", "city", "state", "zip", "country");
    $selection = $header;
    foreach ($topics as $id => $tn)
        if (isset($has->topics[$id])) {
            $header[] = "topic: " . $tn;
            $selection[] = "ti$id";
        }
    downloadCSV($people, $header, "pcinfo", array("selection" => $selection));
    exit;
}


// modifications
function modify_confirm($j, $ok_message, $ok_message_optional) {
    global $Conf;
    if (@$j->ok && @$j->warnings)
        $Conf->warnMsg("<div>" . join("</div><div style='margin-top:0.5em'>", $j->warnings) . "</div>");
    if (@$j->ok && $ok_message && (!$ok_message_optional || !@$j->warnings))
        $Conf->confirmMsg($ok_message);
}

if ($Me->privChair && @$_REQUEST["modifygo"] && check_post() && isset($papersel)) {
    if (@$_REQUEST["modifytype"] == "disableaccount")
        modify_confirm(UserActions::disable($papersel, $Me), "Accounts disabled.", true);
    else if (@$_REQUEST["modifytype"] == "enableaccount")
        modify_confirm(UserActions::enable($papersel, $Me), "Accounts enabled.", true);
    else if (@$_REQUEST["modifytype"] == "resetpassword")
        modify_confirm(UserActions::reset_password($papersel, $Me), "Passwords reset. <a href=\"" . hoturl_post("users", "t=" . $_REQUEST["t"] . "&amp;modifygo=1&amp;modifytype=sendaccount&amp;pap=" . join("+", $papersel)) . "\">Send account information to those accounts</a>", false);
    else if (@$_REQUEST["modifytype"] == "sendaccount")
        modify_confirm(UserActions::send_account_info($papersel, $Me), "Account information sent.", false);
    redirectSelf(array("modifygo" => null, "modifytype" => null));
}


// set scores to view
if (isset($_REQUEST["redisplay"])) {
    $Conf->save_session("ppldisplay", "");
    displayOptionsSet("ppldisplay", "aff", defval($_REQUEST, "showaff", 0));
    displayOptionsSet("ppldisplay", "topics", defval($_REQUEST, "showtop", 0));
    displayOptionsSet("ppldisplay", "tags", defval($_REQUEST, "showtags", 0));
    $Conf->save_session("pplscores", 0);
}
if (isset($_REQUEST["score"]) && is_array($_REQUEST["score"])) {
    $ss = 0;
    foreach ($_REQUEST["score"] as $s)
        $ss |= (1 << $s);
    $Conf->save_session("pplscores", $ss);
}
if (isset($_REQUEST["scoresort"])
    && ($_REQUEST["scoresort"] == "A" || $_REQUEST["scoresort"] == "V"
        || $_REQUEST["scoresort"] == "D"))
    $Conf->save_session("pplscoresort", $_REQUEST["scoresort"]);


if ($_REQUEST["t"] == "pc")
    $title = "Program Committee";
else if (substr($_REQUEST["t"], 0, 3) == "pc:")
    $title = "#" . substr($_REQUEST["t"], 3) . " Program Committee";
else
    $title = "Users";
$Conf->header($title, "accounts", actionBar());


$pl = new ContactList($Me, true);
$pl_text = $pl->text($_REQUEST["t"], hoturl("users", "t=" . $_REQUEST["t"]),
                     $tOpt[$_REQUEST["t"]], 'ppldisplay.$');


// form
echo "<div class='g'></div>\n";
if (count($tOpt) > 1) {
    echo "<table id='contactsform' class='tablinks1'>
<tr><td><div class='tlx'><div class='tld1'>";

    echo Ht::form_div(hoturl("users", "t=" . $_REQUEST["t"]), array("method" => "get"));
    if (isset($_REQUEST["sort"]))
        echo Ht::hidden("sort", $_REQUEST["sort"]);
    echo Ht::select("t", $tOpt, $_REQUEST["t"], array("id" => "contactsform1_d")),
        " &nbsp;", Ht::submit("Go"), "</div></form>";

    echo "</div><div class='tld2'>";

    // Display options
    echo Ht::form_div(hoturl("users"), array("method" => "get"));
    foreach (array("t", "sort") as $x)
        if (isset($_REQUEST[$x]))
            echo Ht::hidden($x, $_REQUEST[$x]);

    echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
    $Conf->footerScript('foldmap.ppl={"aff":2,"tags":3,"topics":1};');
    if ($pl->haveAffrow !== null) {
        echo Ht::checkbox("showaff", 1, $pl->haveAffrow,
                           array("onchange" => "fold('ppl',!this.checked,'aff')")),
            "&nbsp;", Ht::label("Affiliations"), "<br />\n";
    }
    if ($pl->haveTags !== null) {
        echo Ht::checkbox("showtags", 1, $pl->haveTags,
                           array("onchange" => "fold('ppl',!this.checked,'tags')")),
            "&nbsp;", Ht::label("Tags"), "<br />\n";
    }
    if ($pl->haveTopics !== null) {
        echo Ht::checkbox("showtop", 1, $pl->haveTopics,
                           array("onchange" => "fold('ppl',!this.checked,'topics')")),
            "&nbsp;", Ht::label("Topic interests"), "<br />\n";
    }
    echo "</td>";
    if (isset($pl->scoreMax)) {
        echo "<td class='pad'>";
        $theScores = $Conf->session("pplscores", 1);
        $revViewScore = $Me->viewReviewFieldsScore(null, true);
        foreach (ReviewForm::field_list_all_rounds() as $f)
            if ($f->view_score > $revViewScore && $f->has_options) {
                $i = array_search($f->id, $reviewScoreNames);
                echo Ht::checkbox("score[]", $i, $theScores & (1 << $i)),
                    "&nbsp;", Ht::label($f->name_html), "<br />";
            }
        echo "</td>";
    }
    echo "<td>", Ht::submit("redisplay", "Redisplay"), "</td></tr>\n";
    if (isset($pl->scoreMax)) {
        $ss = array();
        foreach (array("A", "V", "D") as $k) /* ghetto array_intersect_key */
            if (isset(ContactList::$score_sorts[$k]))
                $ss[$k] = ContactList::$score_sorts[$k];
        echo "<tr><td colspan='3'><div class='g'></div><b>Sort scores by:</b> &nbsp;",
            Ht::select("scoresort", $ss, $Conf->session("pplscoresort", "A")),
            "</td></tr>";
    }
    echo "</table></div></form>";

    echo "</div></div></td></tr>\n";

    // Tab selectors
    echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a class='tla' onclick='return crpfocus(\"contactsform\", 1)' href=''>User selection</a></div></td>
  <td><div class='tll2'><a class='tla' onclick='return crpfocus(\"contactsform\", 2)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";
}


if ($Me->privChair && $_REQUEST["t"] == "pc")
    $Conf->infoMsg("<p><a href='" . hoturl("profile", "u=new&amp;pc=1") . "' class='button'>Add PC member</a></p><p>Select a PC member’s name to edit their profile or remove them from the PC.</p>");
else if ($Me->privChair && $_REQUEST["t"] == "all")
    $Conf->infoMsg("<p><a href='" . hoturl("profile", "u=new") . "' class='button'>Create account</a></p><p>Select a user to edit their profile.  Select " . Ht::img("viewas.png", "[Act as]") . " to view the site as that user would see it.</p>");


if (isset($pl->any->sel)) {
    echo Ht::form(hoturl_post("users", "t=" . $_REQUEST["t"])), "<div>";
    foreach (array("t", "sort") as $x)
        if (isset($_REQUEST[$x]))
            echo Ht::hidden($x, $_REQUEST[$x]);
}
echo $pl_text;
if (isset($pl->any->sel))
    echo "</div></form>";


$Conf->footer();
