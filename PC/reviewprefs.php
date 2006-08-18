<?php
require_once('../Code/confHeader.inc');
require_once('../Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');
$reviewer = cvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0 || !$Me->amAssistant())
    $reviewer = $Me->contactId;

$Conf->header("Review Preferences", "revpref");

function savePreferences($reviewer) {
    global $Conf, $Me, $reviewTypeName;

    $setting = array();
    $error = false;
    $pmax = 0;
    foreach ($_REQUEST as $k => $v)
	if ($k[0] == 'r' && substr($k, 0, 7) == "revpref"
	    && ($p = cvtint(substr($k, 7))) > 0) {
	    if (($v = cvtpref($v)) >= -1000000 && $v <= 1000000) {
		$setting[$p] = $v;
		$pmax = max($pmax, $p);
	    } else
		$error = true;
	}

    if ($error)
	$Conf->errorMsg("Bad preference setting.  Example settings include '0' or '' (don't care), '+' (want to review, same as +1), '++' (really want to review, same as +2), '&minus;' (don't want to review, same as &minus;1), '&minus;&minus;' (really don't want to review, same as &minus;2), and numbers between &minus;1000000 and 1000000.");
    if ($pmax == 0 && !$error)
	$Conf->errorMsg("No reviewer preferences to update.");
    if ($pmax == 0)
	return;

    $while = "while saving review preferences";
    $result = $Conf->qe("lock tables PaperReviewPreference write", $while);
    if (DB::isError($result))
	return $result;

    $delete = "delete from PaperReviewPreference where contactId=$reviewer and (";
    $orjoin = "";
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p])) {
	    $delete .= $orjoin;
	    if (!isset($setting[$p + 1]))
		$delete .= "paperId=$p";
	    else {
		$delete .= "paperId between $p and ";
		for ($p++; isset($setting[$p + 1]); $p++)
		    /* nada */;
		$delete .= $p;
	    }
	    $orjoin = " or ";
	}
    $Conf->qe($delete . ")", $while);

    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p]))
	    $Conf->qe("insert into PaperReviewPreference set paperId=$p, contactId=$reviewer, preference=$setting[$p]", $while);

    $Conf->qe("unlock tables", $while);
}
if (isset($_REQUEST["update"]))
    savePreferences($reviewer);


$Conf->infoMsg("<p>Help us assign you papers you want by
entering your preferences.
Preferences are integers; the higher the number, the more you want to
review the paper.  0 is neutral (no preference).
For short, \"+\" means \"+1\", \"&minus;\" means \"&minus;1\",
\"++\" means \"+2\", and so forth.</p>

<p>The paper list shows all submitted papers and their topics.  You have high
interest in <span class='topic2'>bold topics</span>, and low interest in <span
class='topic0'>grey topics</span>.  \"Topic score\" is higher the more the you
are interested in the paper's topics.  Click on a column heading to sort by
that column.  You may also enter preferences on the paper pages, which are
accessible by clicking the paper title.</p>");


if ($Me->amAssistant()) {
    echo "<form method='get' action='reviewprefs.php' name='selectReviewer'>
  <b>Showing preferences for</b>
  <select name='reviewer' onchange='document.selectReviewer.submit()'>\n";

    $query = "select ContactInfo.contactId, firstName, lastName,
		count(preference) as preferenceCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReviewPreference on (ContactInfo.contactId=PaperReviewPreference.contactId)
		group by contactId
		order by lastName, firstName, email";
    $result = $Conf->qe($query);
    if (!DB::isError($result))
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    echo "<option value='$row->contactId'";
	    if ($row->contactId == $reviewer)
		echo " selected='selected'";
	    echo ">", contactHtml($row);
	    if ($row->preferenceCount <= 0)
		echo " (no preferences)";
	    echo "</option>";
	}
    echo "</select>\n</form>\n<hr />\n\n";
}
    
$paperList = new PaperList(defval($_REQUEST["sort"]), "reviewprefs.php?reviewer=$reviewer&amp;sort=", "list");
$_SESSION["whichList"] = "list";
echo "<form class='assignpc' method='post' action=\"reviewprefs.php?reviewer=$reviewer&amp;post=1\" enctype='multipart/form-data'>\n";
echo $paperList->text("editReviewPreference", $_SESSION['Me'], $reviewer);
echo "<input class='button_default' type='submit' name='update' value='Save preferences' />\n";
echo "</form>\n";

$Conf->footer();
