<?php
// buzzer.php -- HotCRP buzzer page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// First buzzer version by Nickolai B. Zeldovich
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
$show_papers = true;

// kiosk mode
if ($Me->privChair && isset($_POST["signout_to_kiosk"]) && check_post()) {
    $kiosks = $Conf->setting_json("__tracker_kiosk") ? : (object) array();
    $key = Contact::random_password();
    $kiosks->$key = (object) array("update_at" => $Now, "show_papers" => !!@$_POST["buzzer_showpapers"]);
    // delete old kiosks
    $old_kiosks = array();
    foreach ((array) $kiosks as $k => $kv)
        $old_kiosks[$k] = $kv->update_at;
    asort($old_kiosks);
    foreach ($old_kiosks as $k => $update_at)
        if ($update_at < $Now - 604800 || count((array) $kiosks) > 12)
            unset($kiosks->$k);
    $Conf->save_setting("__tracker_kiosk", 1, $kiosks);
    LoginHelper::logout(false);
    $Me->change_capability("tracker_kiosk", $key);
    redirectSelf();
}

$kiosk = null;
if (($key = $Me->capability("tracker_kiosk"))) {
    $kiosks = $Conf->setting_json("__tracker_kiosk") ? : (object) array();
    if (@$kiosks->$key && $kiosks->$key->update_at >= $Now - 604800)
        $kiosk = $kiosks->$key;
}

if ($kiosk) {
    $Me->is_tracker_kiosk = true;
    $show_papers = $Me->tracker_kiosk_show_papers = $kiosk->show_papers;
}

// user
if (!$Me->isPC && !$Me->is_tracker_kiosk)
    $Me->escape();

// header and script
Ht::stash_script('var buzzer_status = "off", buzzer_muted = false, showpapers = ' . json_encode($show_papers) . ';
function trackertable_paper_row(hc, idx, paper) {
    hc.push("<tr class=\"trackertable" + idx + "\">", "<\/tr>");
    hc.push("<td class=\"trackertable trackerdesc\">", "<\/td>");
    hc.push_pop(idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    hc.push("<td class=\"trackertable trackerpid\">", "<\/td>");
    hc.push_pop(paper.pid && showpapers ? "#" + paper.pid : "");
    hc.push("<td class=\"trackertable trackertitle\">", "<\/td>");
    hc.push_pop(paper.title && showpapers ? text_to_html(paper.title) : "");
    hc.push("<td class=\"trackertable trackerconflicts\">", "<\/td>");
    if (paper.pc_conflicts) {
        for (var i = 0; i < paper.pc_conflicts.length; ++i)
            hc.push((i ? ", " : "") + "<scan class=\"nw\">" + text_to_html(paper.pc_conflicts[i].name) + "<\/span>");
        if (!paper.pc_conflicts.length)
            hc.push("None");
    }
    hc.pop();
    if (idx == 0)
        hc.push("<td id=\"trackerelapsed\"></td>");
    hc.pop();
}
function trackertable() {
    var dl = hotcrp_status, hc = new HtmlCollector;
    if (!dl.tracker)
        hc.push("<h2>No discussion<\h2>");
    else {
        hc.push("<table>", "<\/table>");

        hc.push("<thead><tr>", "<\/tr><\/thead>");
        var any_conflicts = false, i;
        for (i = 0; i < dl.tracker.papers.length; ++i)
            any_conflicts = any_conflicts || dl.tracker.papers[i].pc_conflicts;
        hc.push("<th colspan=\"3\"><\/th>");
        if (any_conflicts)
            hc.push("<th class=\"pl\">PC conflicts<\/th>");
        hc.pop();

        hc.push("<tbody>", "<\/tbody>");
        for (var i = 0; i < dl.tracker.papers.length; ++i)
            trackertable_paper_row(hc, i, dl.tracker.papers[i]);
    }
    jQuery("#trackertable").html(hc.render());
    if (dl.tracker && dl.tracker.position != null)
        hotcrp_deadlines.tracker_show_elapsed();
    if (buzzer_status != "off" && (dl.tracker_status || "off") != "off"
        && buzzer_status != dl.tracker_status && !buzzer_muted) {
        var sound = jQuery("#buzzer")[0];
        sound.pause();
        sound.currentTime = 0;
        sound.play();
    }
    buzzer_status = dl.tracker_status || "off";
}
function trackertable_mute(elt) {
    fold(elt);
    buzzer_muted = jQuery(elt).hasClass("foldo");
}
function trackertable_showpapers() {
    var e = jQuery("#buzzer_showpapers");
    if (e && !!showpapers != !!e.is(":checked")) {
        showpapers = !showpapers;
        trackertable();
    }
}
jQuery(window).on("hotcrp_deadlines", function (evt, dl) {
    evt.preventDefault();
    jQuery(trackertable);
})');
$Conf->header("Discussion status", "buzzerpage", false);

echo '<div id="trackertable" style="margin-top:1em"></div>';
echo "<audio id=\"buzzer\"><source src=\"", Ht::$img_base, "buzzer.mp3\"></audio>";

echo Ht::form(hoturl_post("buzzer"));
echo '<table style="margin-top:3em"><tr>';

// mute button
echo '<td><button type="button" class="foldc" style="padding-bottom:5px" onclick="trackertable_mute(this)">
<svg id="soundicon" class="fn" width="1.5em" height="1.5em" viewBox="0 0 75 75" style="position:relative;bottom:-3px">
 <polygon points="39.389,13.769 22.235,28.606 6,28.606 6,47.699 21.989,47.699 39.389,62.75 39.389,13.769" style="stroke:#111111;stroke-width:5;stroke-linejoin:round;fill:#111111;" />
 <path d="M 48.128,49.03 C 50.057,45.934 51.19,42.291 51.19,38.377 C 51.19,34.399 50.026,30.703 48.043,27.577" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path d="M 55.082,20.537 C 58.777,25.523 60.966,31.694 60.966,38.377 C 60.966,44.998 58.815,51.115 55.178,56.076" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path d="M 61.71,62.611 C 66.977,55.945 70.128,47.531 70.128,38.378 C 70.128,29.161 66.936,20.696 61.609,14.01" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
</svg><svg id="muteicon" class="fx" width="1.5em" height="1.5em" viewBox="0 0 75 75" style="position:relative;bottom:-3px">
 <polygon points="39.389,13.769 22.235,28.606 6,28.606 6,47.699 21.989,47.699 39.389,62.75 39.389,13.769" style="stroke:#111111;stroke-width:5;stroke-linejoin:round;fill:#111111;" />
 <path d="M 48.651772,50.269646 69.395223,25.971024" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path d="M 69.395223,50.269646 48.651772,25.971024" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round" />
</svg></button></td>';
//echo '<span class="hidden fhn_ib">&nbsp;Mute</span>';
//echo '<span class="hidden fhx_ib">&nbsp;Unmute</span></button></td>';

// show-papers
if ($Me->has_database_account()) {
    echo '<td style="padding-left:2em">',
        Ht::checkbox("buzzer_showpapers", 1, $show_papers,
                     array("id" => "buzzer_showpapers",
                           "onclick" => "trackertable_showpapers()")),
        "&nbsp;", Ht::label("Show papers"), '</td>';
    Ht::stash_script("trackertable_showpapers()");
}

// kiosk mode
if ($Me->privChair)
    echo '<td style="padding-left:2em">',
        Ht::submit("signout_to_kiosk", "Kiosk"),
        '</td>';

echo "</tr></table></form>\n";
$Conf->footer();
