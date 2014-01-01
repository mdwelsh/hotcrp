<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Information from the SQL definition
    public $contactId = 0;
    private $cid;               // for forward compatibility
    var $visits;
    var $firstName = "";
    var $lastName = "";
    var $email = "";
    var $preferredEmail = "";
    var $sorter = "";
    var $affiliation;
    var $collaborators;
    var $voicePhoneNumber;
    var $password = "";
    public $password_type = 0;
    public $password_plaintext = "";
    public $disabled = false;
    var $note;
    var $defaultWatch = WATCH_COMMENT;

    // Address information (loaded separately)
    var $addressLine1;
    var $addressLine2;
    var $city;
    var $state;
    var $zipCode;
    var $country;

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_ERC = 8;
    const ROLE_PCERC = 9;
    const ROLE_PCLIKE = 15;
    private $is_author_;
    private $has_review_;
    private $has_outstanding_review_;
    private $is_requester_;
    private $is_lead_;
    var $roles = 0;
    var $isPC = false;
    var $privChair = false;
    var $contactTags = null;
    const CAP_AUTHORVIEW = 1;
    var $capabilities;


    public function __construct() {
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name == "cid")
            return $this->contactId;
        else
            return null;
    }

    public function __set($name, $value) {
        if ($name == "cid")
            $this->contactId = $value;
        else
            $this->$name = $value;
    }

    static public function set_sorter($c) {
        global $Opt;
        if (@$Opt["sortByLastName"]) {
            if (($m = Text::analyze_von($c->lastName)))
                $c->sorter = trim("$m[1] $c->firstName $m[0] $c->email");
            else
                $c->sorter = trim("$c->lastName $c->firstName $c->email");
        } else
            $c->sorter = trim("$c->firstName $c->lastName $c->email");
    }

    static public function compare($a, $b) {
        return strcasecmp($a->sorter, $b->sorter);
    }

    static public function make($o) {
	// If you change this function, search for its callers to ensure
	// they provide all necessary information.
	$c = new Contact;
	$c->contactId = (int) $o->contactId;
	$c->firstName = defval($o, "firstName", "");
	$c->lastName = defval($o, "lastName", "");
	$c->email = defval($o, "email", "");
	$c->preferredEmail = defval($o, "preferredEmail", "");
        self::set_sorter($c);
	$c->password = defval($o, "password", "");
        $c->password_type = (substr($c->password, 0, 1) == " " ? 1 : 0);
        if ($c->password_type == 0)
            $c->password_plaintext = $c->password;
        $c->disabled = !!defval($o, "disabled", false);
        if (isset($o->has_review))
            $c->has_review_ = $o->has_review;
        if (isset($o->has_outstanding_review))
            $c->has_outstanding_review_ = $o->has_outstanding_review;
	$c->roles = defval($o, "roles", 0);
	if (defval($o, "isPC", false))
	    $c->roles |= self::ROLE_PC;
	if (defval($o, "isAssistant", false))
	    $c->roles |= self::ROLE_ADMIN;
	if (defval($o, "isChair", false))
	    $c->roles |= self::ROLE_CHAIR;
	$c->isPC = ($c->roles & self::ROLE_PCLIKE) != 0;
	$c->privChair = ($c->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
        $c->contactTags = defval($o, "contactTags", null);
	return $c;
    }


    //
    // Initialization functions
    //

    function activate() {
        global $Opt;

        // Set $_SESSION["adminuser"] based on administrator status
        if ($this->contactId > 0 && !$this->privChair
            && @$_SESSION["adminuser"] == $this->contactId)
            unset($_SESSION["adminuser"]);
        else if ($this->privChair && !@$_SESSION["adminuser"])
            $_SESSION["adminuser"] = $this->contactId;

        // Handle adminuser actas requests
        if (@$_SESSION["adminuser"] && isset($_REQUEST["actas"])) {
            $cid = cvtint($_REQUEST["actas"]);
            if ($cid <= 0 && $_REQUEST["actas"] == "admin"
                && @$_SESSION["adminuser"])
                $cid = (int) $_SESSION["adminuser"];
            else if ($cid <= 0)
                $cid = Contact::id_by_email($_REQUEST["actas"]);
            unset($_REQUEST["actas"]);
            if ($cid > 0) {
                if (($newc = Contact::find_by_id($cid))) {
                    unset($_SESSION["l"]);
                    if ($newc->contactId != $_SESSION["adminuser"])
                        $_SESSION["actasuser"] = $newc->email;
                    return $newc->activate();
                }
            }
        }

        // Handle invalidate-caches requests
        if (@$_REQUEST["invalidatecaches"] && @$_SESSION["adminuser"]) {
            unset($_REQUEST["invalidatecaches"]);
            $Conf->invalidateCaches();
        }

        // If validatorContact is set, use it
        if ($this->contactId <= 0 && @$Opt["validatorContact"]
            && @$_REQUEST["validator"]) {
            unset($_REQUEST["validator"]);
            if (($newc = Contact::find_by_email($Opt["validatorContact"])))
                return $newc->activate();
        }

        // Add capabilities
        if (!@$Opt["disableCapabilities"])
            $this->update_capabilities(true);

        // Set user session
        if ($this->contactId)
            $_SESSION["user"] = "$this->contactId " . $Opt["dsn"] . " $this->email";
        else
            unset($_SESSION["user"]);
        return $this;
    }

    private function update_capabilities($use_session) {
        global $Conf, $Opt;

        // Set capability key (should happen only first time)
        if (!$Conf->setting("cap_key")) {
            $key = hotcrp_random_bytes(16);
            if ($key && ($key = base64_encode($key))
                && $Conf->qx("insert into Settings (name, value, data) values ('cap_key', 1, '" . sqlq($key) . "')"))
                $Conf->updateSettings();
            else
                return ($Opt["disableCapabilities"] = true);
        }

        // Add capabilities from session
        if ($use_session && @$_SESSION["capabilities"])
            $this->capabilities = $_SESSION["capabilities"];

        // Add capabilities from arguments
        $changed = false;
        if (@$_REQUEST["cap"] && $_REQUEST["cap"][0] == "0") {
            if (preg_match('/\A0([1-9]\d*)a\S+\z/', $_REQUEST["cap"], $m)
                && ($result = $Conf->qx("select paperId, capVersion from Paper where paperId=$m[1]"))
                && ($row = edb_orow($result))) {
                $rowcap = $Conf->capabilityText($row, "a");
                if ($_REQUEST["cap"] == $rowcap
                    || str_replace("/", "_", $_REQUEST["cap"]) == $rowcap) {
                    $this->change_capability($m[1], Contact::CAP_AUTHORVIEW, true);
                    $changed = true;
                }
            }
            unset($_REQUEST["cap"]);
        }

        // Support capability testing
        if (@$Opt["testCapabilities"] && @$_REQUEST["testcap"]
            && preg_match_all('/([-+]?)([1-9]\d*)([A-Za-z]+)/',
                              $_REQUEST["testcap"], $m, PREG_SET_ORDER)) {
	    foreach ($m as $mm) {
		$c = ($mm[3] == "a" ? Contact::CAP_AUTHORVIEW : 0);
		$this->change_capability($mm[2], $c, $mm[1] != "-");
                $changed = true;
	    }
            unset($_REQUEST["testcap"]);
        }

        // Save capabilities in session
        if ($changed && $use_session) {
            if (@$this->capabilities)
                $_SESSION["capabilities"] = $this->capabilities;
            else
                unset($_SESSION["capabilities"]);
        }
    }

    function is_empty() {
        return $this->contactId <= 0 && !@$this->capabilities;
    }

    function is_known_user() {
	return $this->contactId > 0;
    }

    function is_core_pc() {
        return ($this->roles & self::ROLE_PCLIKE) != 0
            && ($this->roles & self::ROLE_ERC) == 0;
    }

    function is_erc() {
        return ($this->roles & self::ROLE_PCERC) == self::ROLE_PCERC;
    }

    function update_cached_roles() {
        foreach (array("is_author_", "has_review_", "has_outstanding_review_",
                       "is_requester_", "is_lead_") as $k)
            unset($this->$k);
    }

    private function load_author_reviewer_status() {
        global $Me, $Conf;

        // Load from database
        if ($this->contactId > 0) {
            $qr = "";
            if ($Me->contactId == $this->contactId
                && isset($_SESSION["rev_tokens"]))
                $qr = " or r.reviewToken in (" . join(", ", $_SESSION["rev_tokens"]) . ")";
            $result = $Conf->qe("select max(conf.conflictType),
		r.contactId as reviewer,
		max(r.reviewNeedsSubmit) as reviewNeedsSubmit
		from ContactInfo c
		left join PaperConflict conf on (conf.contactId=c.contactId)
		left join PaperReview r on (r.contactId=c.contactId$qr)
		where c.contactId=$this->contactId group by c.contactId");
            $row = edb_row($result);
        } else
            $row = null;
        $this->is_author_ = $row && $row[0] >= CONFLICT_AUTHOR;
        $this->has_review_ = $row && $row[1] > 0;
        $this->has_outstanding_review_ = $row && $row[2] > 0;

        // Update contact information from capabilities
        if (@$this->capabilities)
            foreach ($this->capabilities as $pid => $cap)
                if ($cap & self::CAP_AUTHORVIEW)
                    $this->is_author_ = true;
    }

    function is_author() {
        if (!isset($this->is_author_))
            $this->load_author_reviewer_status();
        return $this->is_author_;
    }

    function is_reviewer() {
        if (!$this->isPC && !isset($this->has_review_))
            $this->load_author_reviewer_status();
	return $this->isPC || $this->has_review_;
    }

    function has_review() {
        if (!isset($this->has_review_))
            $this->load_author_reviewer_status();
        return $this->has_review_;
    }

    function has_outstanding_review() {
        if (!isset($this->has_outstanding_review_))
            $this->load_author_reviewer_status();
        return $this->has_outstanding_review_;
    }

    function is_requester() {
        global $Conf;
        if (!isset($this->is_requester_)) {
	    $result = $Conf->qe("select epr.requestedBy from PaperReview epr
		where epr.requestedBy=$this->contactId limit 1");
            $row = edb_row($result);
            $this->is_requester_ = $row && $row[0] > 1;
        }
        return $this->is_requester_;
    }

    static function roles_all_contact_tags($roles, $tags) {
        $t = "";
        if ($roles & self::ROLE_PC)
            $t = " pc" . ($roles & self::ROLE_ERC ? " erc" : " corepc");
        if ($tags)
            return $t . $tags;
        else
            return $t ? $t . " " : "";
    }

    function all_contact_tags() {
        return self::roles_all_contact_tags($this->roles, $this->contactTags);
    }

    static function _addressKeys() {
	return array("addressLine1", "addressLine2", "city", "state",
		     "zipCode", "country");
    }

    function change_capability($pid, $c, $on) {
	$newcap = defval($this, "capabilities", array());
	$v = (defval($newcap, $pid, 0) | ($on ? $c : 0)) & ~($on ? 0 : $c);
	if ($v)
	    $newcap[$pid] = $v;
	else
	    unset($newcap[$pid]);
	if (count($newcap))
	    $this->capabilities = $newcap;
	else
	    unset($this->capabilities);
    }

    function trim() {
	$this->contactId = (int) trim($this->contactId);
	$this->visits = trim($this->visits);
	$this->firstName = simplify_whitespace($this->firstName);
	$this->lastName = simplify_whitespace($this->lastName);
	foreach (array("email", "preferredEmail", "affiliation",
		       "voicePhoneNumber", "note",
		       "addressLine1", "addressLine2", "city", "state",
		       "zipCode", "country")
		 as $k)
	    if ($this->$k)
		$this->$k = trim($this->$k);
        self::set_sorter($this);
    }

    function exit_if_empty() {
	global $Conf;
	if ($this->is_empty()) {
	    if (defval($_REQUEST, "ajax"))
		$Conf->ajaxExit(array("ok" => 0, "loggedout" => 1));
	    $x = array("afterLogin" => 1, "blind" => 1);
	    $rf = reviewForm();
	    // Preserve review form values.
	    foreach ($rf->fmap as $field => $f)
		if (isset($_REQUEST[$field]))
		    $x[$field] = $_REQUEST[$field];
	    // Preserve comments and other long-to-type things.
	    foreach (array("comment", "visibility", "override", "plimit",
			   "subject", "emailBody", "cc", "recipients",
			   "replyto") as $k)
		if (isset($_REQUEST[$k]))
		    $x[$k] = $_REQUEST[$k];
	    // NB: selfHref automagically preserves common parameters like
	    // "p", "q", etc.
	    $_SESSION["afterLogin"] = selfHref($x, false);
	    error_go(false, "You have invalid credentials and need to sign in.");
	}
    }

    function goIfNotPC() {
	if (!$this->privChair && !$this->isPC)
	    error_go(false, "That page is only accessible to program committee members.");
    }

    function goIfNotPrivChair() {
	if (!$this->privChair)
	    error_go(false, "That page is only accessible to conference administrators.");
    }

    function updateDB($where = "") {
	global $Conf;
	$this->trim();
	$qa = ", roles='$this->roles', defaultWatch='$this->defaultWatch'";
	if ($this->preferredEmail != "")
	    $qa .= ", preferredEmail='" . sqlq($this->preferredEmail) . "'";
	if ($Conf->sversion >= 35) {
	    if ($this->contactTags)
		$qa .= ", contactTags='" . sqlq($this->contactTags) . "'";
	    else
		$qa .= ", contactTags=null";
	}
        if ($Conf->sversion >= 47)
            $qa .= ", disabled=" . ($this->disabled ? 1 : 0);
	$query = sprintf("update ContactInfo set firstName='%s', lastName='%s',
		email='%s', affiliation='%s', voicePhoneNumber='%s',
		password='%s', collaborators='%s'$qa
		where contactId='%s'",
			 sqlq($this->firstName), sqlq($this->lastName),
			 sqlq($this->email), sqlq($this->affiliation),
			 sqlq($this->voicePhoneNumber),
			 sqlq($this->password),
			 sqlq($this->collaborators),
			 $this->contactId);
	$result = $Conf->qe($query, $where);
	if (!$result)
	    return $result;
	$Conf->qx("delete from ContactAddress where contactId=$this->contactId");
	if ($this->addressLine1 || $this->addressLine2 || $this->city
	    || $this->state || $this->zipCode || $this->country) {
	    $query = "insert into ContactAddress (contactId, addressLine1, addressLine2, city, state, zipCode, country) values ($this->contactId, '" . sqlq($this->addressLine1) . "', '" . sqlq($this->addressLine2) . "', '" . sqlq($this->city) . "', '" . sqlq($this->state) . "', '" . sqlq($this->zipCode) . "', '" . sqlq($this->country) . "')";
	    $result = $Conf->qe($query, $where);
	}
	return $result;
    }

    static function email_authored_papers($email, $reg) {
        global $Conf;
        $aupapers = array();
        $result = $Conf->q("select paperId, authorInformation from Paper where authorInformation like '%	" . sqlq_for_like($email) . "	%'");
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au)
		if (strcasecmp($au[2], $email) == 0) {
                    $aupapers[] = $row->paperId;
                    if ($reg && !@$reg->firstName && $au[0])
                        $reg->firstName = $au[0];
                    if ($reg && !@$reg->lastName && $au[1])
                        $reg->lastName = $au[1];
                    if ($reg && !@$reg->affiliation && $au[3])
                        $reg->affiliation = $au[3];
                    break;
                }
        }
        return $aupapers;
    }

    function save_authored_papers($aupapers) {
        global $Conf;
        if (count($aupapers)) {
            $q = array();
            foreach ($aupapers as $pid)
                $q[] = "($pid, $this->contactId, " . CONFLICT_AUTHOR . ")";
            $Conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $q) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");
        }
    }

    private function load_by_query($query) {
	global $Conf, $Opt;

	$result = $Conf->q($query);
        if (!($row = edb_orow($result)))
            return false;

        $this->contactId = (int) $row->contactId;
        $this->visits = $row->visits;
        $this->firstName = $row->firstName;
        $this->lastName = $row->lastName;
        $this->email = $row->email;
        $this->preferredEmail = defval($row, "preferredEmail", null);
        self::set_sorter($this);
        $this->affiliation = $row->affiliation;
        $this->voicePhoneNumber = $row->voicePhoneNumber;
        $this->password = $row->password;
        $this->password_type = (substr($this->password, 0, 1) == " " ? 1 : 0);
        if ($this->password_type == 0)
            $this->password_plaintext = $this->password;
        $this->disabled = !!defval($row, "disabled", 0);
        $this->note = $row->note;
        $this->collaborators = $row->collaborators;
        $this->defaultWatch = defval($row, "defaultWatch", 0);
        $this->contactTags = defval($row, "contactTags", null);

        $this->roles = $row->roles;
        $this->isPC = ($this->roles & self::ROLE_PCLIKE) != 0;
        $this->privChair = ($this->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;

        $this->trim();
        return true;
    }

    static function find_by_id($cid) {
        $acct = new Contact;
        if (!$acct->load_by_query("select c.* from ContactInfo c where c.contactId=" . (int) $cid))
            return null;
        return $acct;
    }

    static function safe_registration($reg) {
        $safereg = array();
        foreach (array("firstName", "lastName", "name", "preferredEmail",
                       "affiliation", "collaborators", "voicePhoneNumber")
                 as $k)
            if (isset($reg[$k]))
                $safereg[$k] = $reg[$k];
        return $safereg;
    }

    private function register_by_email($email, $reg) {
        global $Conf, $Opt, $Now;
        $reg = (object) ($reg === true ? array() : $reg);

        // Set up registration
        if (!isset($reg->firstName) && !isset($reg->lastName)
            && isset($reg->name)) {
            $matches = Text::split_name($reg->name);
            $reg->firstName = $matches[0];
            $reg->lastName = $matches[1];
        }

        $this->password_type = 0;
        if (isset($reg->password)
            && ($password = trim($reg->password)) != "")
            $this->change_password($password);
        else {
            // Always store initial, randomly-generated user passwords in
            // plaintext. The first time a user logs in, we will encrypt
            // their password.
            //
            // Why? (1) There is no real security problem to storing random
            // values. (2) We get a better UI by storing the textual password.
            // Specifically, if someone tries to "create an account", then
            // they don't get the email, then they try to create the account
            // again, the password will be visible in both emails.
            $this->password = $password = self::random_password();
        }

        $best_email = @$reg->preferredEmail ? $reg->preferredEmail : $email;
	$authored_papers = Contact::email_authored_papers($best_email, $reg);

        // Set up query
        $qa = "email, password, creationTime";
        $qb = "'" . sqlq($email) . "','" . sqlq($this->password) . "',$Now";
        foreach (array("firstName", "lastName", "affiliation",
                       "collaborators", "voicePhoneNumber", "preferredEmail")
                 as $k)
            if (isset($reg->$k)) {
                $qa .= ",$k";
                $qb .= ",'" . sqlq($reg->$k) . "'";
            }

        $result = $Conf->q("insert into ContactInfo ($qa) values ($qb)");
        if (!$result)
            return false;
        $cid = (int) $Conf->lastInsertId("while creating contact");
        if (!$cid)
            return false;

        // Having added, load it
        if (!$this->load_by_query("select c.* from ContactInfo c where c.contactId=$cid"))
            return false;

        // Success! Save newly authored papers
        if (count($authored_papers))
            $this->save_authored_papers($authored_papers);
        $this->password_plaintext = $password;
        return true;
    }

    static function find_by_email($email, $reg = false, $send = false) {
        global $Conf, $Me, $Now;
        $acct = new Contact;

        // Lookup by email
        $email = trim($email ? $email : "");
        if ($email != ""
            && $acct->load_by_query("select c.* from ContactInfo c where c.email='" . sqlq($email) . "'"))
            return $acct;

        // Not found: register
        if (!$reg || !validateEmail($email))
            return null;
        $ok = $acct->register_by_email($email, $reg);

        // Log
	if ($ok) {
            if ($Me->privChair)
                $Conf->infoMsg("Created account for " . htmlspecialchars($email) . ".");
            if ($send)
                $acct->sendAccountInfo(true, false);
	    $Conf->log($Me->is_known_user() ? "Created account ($Me->email)" : "Created account", $acct);
	} else
	    $Conf->log("Account $email creation failure", $Me);

	return $ok ? $acct : null;
    }

    function lookupAddress() {
	global $Conf;
	$result = $Conf->qx("select * from ContactAddress where contactId=$this->contactId");
	foreach (self::_addressKeys() as $k)
	    $this->$k = null;
	if (($row = edb_orow($result)))
	    foreach (self::_addressKeys() as $k)
		$this->$k = $row->$k;
    }

    static function id_by_email($email) {
        global $Conf;
        $result = $Conf->qe("select contactId from ContactInfo where email='" . sqlq(trim($email)) . "'");
        $row = edb_row($result);
        return $row ? $row[0] : false;
    }


    // viewing permissions

    function _fetchPaperRow($prow, &$whyNot) {
	global $Conf;
	if (!is_object($prow))
	    return $Conf->paperRow($prow, $this->contactId, $whyNot);
	else {
	    $whyNot = array("paperId" => $prow->paperId);
	    return $prow;
	}
    }

    static public function override_deadlines() {
        return isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }

    static public function override_conflict($forceShow = null) {
        if ($forceShow === null)
            return isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] > 0;
        else
            return $forceShow;
    }

    public function allowAdminister($prow) {
        if ($prow && $prow->conflictType > 0 && $prow->managerContactId
            && $prow->managerContactId != $this->contactId)
            return false;
        return $this->privChair
            || ($prow && $prow->managerContactId && $prow->managerContactId == $this->contactId);
    }

    public function canAdminister($prow, $forceShow = null) {
        if ($prow && $prow->conflictType > 0 && $prow->managerContactId
            && $prow->managerContactId != $this->contactId)
            return false;
        return ($this->privChair
                || ($prow && $prow->managerContactId
                    && $prow->managerContactId == $this->contactId))
            && (!$prow || $prow->conflictType <= 0
                || self::override_conflict($forceShow));
    }

    public function actPC($prow, $forceShow = null) {
        if ($prow && $prow->conflictType > 0 && $prow->managerContactId
            && $prow->managerContactId != $this->contactId)
            return false;
        return $this->isPC
            && (!$prow || $prow->conflictType <= 0
                || (($this->privChair || $prow->managerContactId == $this->contactId)
                    && self::override_conflict($forceShow)));
    }

    public function actConflictType($prow) {
	global $Conf;
	// If an author-view capability is set, then use it -- unless this
	// user is a PC member or reviewer, which takes priority.
	if (isset($this->capabilities)
	    && isset($this->capabilities[$prow->paperId])
	    && ($this->capabilities[$prow->paperId] & self::CAP_AUTHORVIEW)
	    && !$this->isPC
	    && $prow->myReviewType <= 0)
	    return CONFLICT_AUTHOR;
	return $prow->conflictType;
    }

    public function actAuthorView($prow, $download = false) {
	return $this->actConflictType($prow) >= CONFLICT_AUTHOR;
    }

    public function actAuthorSql($table, $only_if_complex = false) {
	$m = array("$table.conflictType>=" . CONFLICT_AUTHOR);
	if (isset($this->capabilities) && !$this->isPC)
	    foreach ($this->capabilities as $pid => $cap)
		if ($cap & Contact::CAP_AUTHORVIEW)
		    $m[] = "Paper.paperId=$pid";
	if (count($m) > 1)
	    return "(" . join(" or ", $m) . ")";
	else
	    return $only_if_complex ? false : $m[0];
    }

    function canStartPaper(&$whyNot = null) {
	global $Conf;
	$whyNot = array();
	if ($Conf->timeStartPaper()
            || ($this->privChair && self::override_deadlines()))
	    return true;
	$whyNot["deadline"] = "sub_reg";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canEditPaper($prow, &$whyNot = null) {
	return $prow->conflictType >= CONFLICT_AUTHOR
            || $this->allowAdminister($prow);
    }

    function canUpdatePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn <= 0
	    && ($Conf->timeUpdatePaper($prow)
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0 && $Conf->setting('sub_freeze') > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$Conf->timeUpdatePaper($prow)
            && !($admin && self::override_deadlines()))
	    $whyNot["deadline"] = "sub_update";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canFinalizePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn <= 0
	    && ($Conf->timeFinalizePaper($prow)
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$Conf->timeFinalizePaper($prow)
            && !($admin && self::override_deadlines()))
	    $whyNot["deadline"] = "finalizePaperSubmission";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canWithdrawPaper($prow, &$whyNot = null, $override = false) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
        $override = $admin && ($override || self::override_deadlines());
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn <= 0
            && ($override || $prow->outcome == 0))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
        else if ($prow->outcome != 0 && !$override)
            $whyNot["decided"] = 1;
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canRevivePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn > 0
	    && ($Conf->timeUpdatePaper($prow)
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn <= 0)
	    $whyNot["notWithdrawn"] = 1;
	if (!$Conf->timeUpdatePaper($prow)
            && !($admin && self::override_deadlines()))
	    $whyNot["deadline"] = "sub_update";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canSubmitFinalPaper($prow, &$whyNot = null, $override = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	$override = $admin && ($override || self::override_deadlines());
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $Conf->collectFinalPapers()
	    && $prow->timeWithdrawn <= 0 && $prow->outcome > 0
	    && ($Conf->timeSubmitFinalPaper() || $override))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	// NB logic order here is important elsewhere
	if (!$Conf->collectFinalPapers()
	    || (!$Conf->timeAuthorViewDecision() && !$override))
	    $whyNot["deadline"] = "final_open";
	else if ($prow->outcome <= 0)
	    $whyNot["notAccepted"] = 1;
	else if (!$Conf->timeSubmitFinalPaper() && !$override)
	    $whyNot["deadline"] = "final_done";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canViewPaper($prow, &$whyNot = null, $download = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if ($conflictType >= CONFLICT_AUTHOR
	    || $admin
	    || ($prow->myReviewType > 0 && $Conf->timeReviewerViewSubmittedPaper())
	    || ($this->isPC && $Conf->timePCViewPaper($prow, $download)))
	    return true;
	// collect failure reasons
	if (!$this->isPC && $conflictType < CONFLICT_AUTHOR
	    && $prow->myReviewType <= 0) {
	    $whyNot["permission"] = 1;
	    return false;
	}
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	if ($this->isPC && !$Conf->timePCViewPaper($prow, $download))
	    $whyNot["deadline"] = "sub_sub";
	else if ($prow->myReviewType > 0 && !$Conf->timeReviewerViewSubmittedPaper())
	    $whyNot["deadline"] = "sub_sub";
	if ((!$this->isPC && $prow->myReviewType <= 0) || count($whyNot) == 1)
	    $whyNot["permission"] = 1;
	return false;
    }

    function canDownloadPaper($prow, &$whyNot = null) {
	return $this->canViewPaper($prow, $whyNot, true);
    }

    function canViewPaperManager($prow) {
        global $Opt;
        return $this->privChair
            || ($prow && $prow->managerContactId == $this->contactId)
            || (($this->isPC || ($prow && $prow->myReviewType > 0))
                && defval($Opt, "hideManager", false));
    }

    function allowViewAuthors($prow, &$whyNot = null) {
        return $this->canViewAuthors($prow, true, $whyNot);
    }

    function canViewAuthors($prow, $forceShow = null, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	$conflictType = $this->actConflictType($prow);
        $bs = $Conf->setting("sub_blind");
        $nonblind = ((($this->isPC || $prow->myReviewType > 0)
                      && $Conf->timeReviewerViewAcceptedAuthors()
                      && $prow->outcome > 0)
                     || $bs == BLIND_NEVER
                     || ($bs == BLIND_OPTIONAL
                         && !(isset($prow->paperBlind) ? $prow->paperBlind : $prow->blind))
                     || ($bs == BLIND_UNTILREVIEW
                         && defval($prow, "myReviewSubmitted")));
        if (($nonblind && $prow->timeSubmitted > 0
             && ($this->isPC
                 || ($prow->myReviewType > 0
                     && $Conf->timeReviewerViewSubmittedPaper())))
            || ($nonblind && $prow->timeWithdrawn <= 0 && $this->isPC
                && $Conf->setting("pc_seeall") > 0)
            || ($this->privChair || $this->allowAdminister($prow)
                ? self::override_conflict($forceShow)
                : $conflictType >= CONFLICT_AUTHOR))
            return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	else if ($this->isPC || $prow->myReviewType > 0)
	    $whyNot["blindSubmission"] = 1;
	else
	    $whyNot["permission"] = 1;
	return false;
    }

    function canViewPaperOption($prow, $opt, $forceShow = null,
                                &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	if (!is_object($opt) && !($opt = PaperOption::get($opt))) {
	    $whyNot["invalidId"] = "paper";
	    return false;
	}
        $admin = $this->privChair || $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if (!$this->canViewPaper($prow, $whyNot))
	    return false;	// $whyNot already set
        if ($conflictType >= CONFLICT_AUTHOR
            || (($admin || $this->isPC || ($prow && $prow->myReviewType > 0))
                && (($opt->pcView == 0 && $admin)
                    || $opt->pcView == 1
                    || ($opt->pcView == 2
                        && $this->canViewAuthors($prow, $forceShow)))))
            return true;
	$whyNot["permission"] = 1;
	return false;
    }

    function ownReview($rrow) {
	global $Conf;
	if (!$rrow || !$rrow->reviewId)
	    return false;
	$rrow_contactId = 0;
	if (isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if (isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	return $rrow_contactId == $this->contactId
	    || (isset($_SESSION["rev_tokens"]) && array_search($rrow->reviewToken, $_SESSION["rev_tokens"]) !== false)
	    || ($rrow->requestedBy == $this->contactId && $rrow->reviewType == REVIEW_EXTERNAL && $Conf->setting("pcrev_editdelegate"));
    }

    public function canCountReview($prow, $rrow, $forceShow) {
        if ($rrow && $rrow->reviewNeedsSubmit <= 0
            && $rrow->reviewSubmitted <= 0)
            return false;
        else if ($this->isPC
                 && (!$prow || $this->actConflictType($prow) == 0))
            return true;
        else
            return $this->canViewReview($prow, $rrow, $forceShow);
    }

    function canViewReview($prow, $rrow, $forceShow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $forceShow = self::override_conflict($forceShow);
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else
            $viewscore = VIEWSCORE_AUTHOR;
	$rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
        $pc_seeallrev = 0;
        if ($this->is_core_pc())
            $pc_seeallrev = $Conf->setting("pc_seeallrev");
        $admin = $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if ($admin && $forceShow)
	    return true;
	if (($prow->timeSubmitted > 0
	     || defval($prow, "myReviewType") > 0
	     || $admin)
	    && (($conflictType >= CONFLICT_AUTHOR
		 && $Conf->timeAuthorViewReviews($this->has_outstanding_review() && $this->has_review())
		 && $rrowSubmitted
                 && $viewscore >= VIEWSCORE_AUTHOR)
		|| ($admin
                    && $conflictType == 0)
		|| ($this->isPC
		    && $conflictType == 0 && $rrowSubmitted
		    && $pc_seeallrev > 0	// see also timePCViewAllReviews()
		    && ($pc_seeallrev != 4 || !$this->has_outstanding_review())
		    && ($pc_seeallrev != 3 || !defval($prow, "myReviewType"))
                    && $viewscore >= VIEWSCORE_PC)
		|| (defval($prow, "myReviewType") > 0
		    && $conflictType == 0
                    && $rrowSubmitted
		    && (defval($prow, "myReviewSubmitted") > 0
			|| defval($prow, "myReviewNeedsSubmit", 1) == 0)
		    && ($this->isPC || $Conf->settings["extrev_view"] >= 1)
                    && $viewscore >= VIEWSCORE_PC)
		|| ($rrow
                    && $rrow->paperId == $prow->paperId
		    && $this->ownReview($rrow)
                    && $viewscore >= VIEWSCORE_REVIEWERONLY)))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	else if ($conflictType < CONFLICT_AUTHOR && !$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR && $Conf->timeAuthorViewReviews()
		 && $this->has_outstanding_review() && $this->has_review())
	    $whyNot['reviewsOutstanding'] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR && !$rrowSubmitted)
	    $whyNot['permission'] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR)
	    $whyNot['deadline'] = 'au_seerev';
	else if ($conflictType > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->myReviewType > 0
                 && !$this->is_core_pc()
                 && $prow->myReviewSubmitted > 0)
	    $whyNot['externalReviewer'] = 1;
	else if (!$rrowSubmitted)
	    $whyNot['reviewNotSubmitted'] = 1;
	else if ($this->isPC && $pc_seeallrev == 4
                 && $this->has_outstanding_review())
	    $whyNot["reviewsOutstanding"] = 1;
	else if (!$Conf->timeReviewOpen())
	    $whyNot['deadline'] = "rev_open";
	else {
	    $whyNot['reviewNotComplete'] = 1;
	    if (!$Conf->time_review($this->isPC, true))
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	}
	if ($admin)
	    $whyNot['forceShow'] = 1;
	return false;
    }

    function canRequestReview($prow, $time, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->myReviewType >= REVIEW_SECONDARY || $admin)
	    && ($Conf->time_review(false, true) || !$time
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->myReviewType < REVIEW_SECONDARY)
	    $whyNot['permission'] = 1;
	else {
	    $whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($admin)
		$whyNot['override'] = 1;
	}
	return false;
    }

    function can_review_any() {
        global $Conf;
        return $this->is_core_pc() && $Conf->setting("pcrev_any") > 0
            && $Conf->time_review(true, true);
    }

    function timeReview($prow, $rrow) {
	global $Conf;
	if ($prow->myReviewType || $prow->reviewId
            || ($rrow && $this->ownReview($rrow))
	    || ($rrow && $rrow->contactId != $this->contactId
                && $this->allowAdminister($prow)))
            return $Conf->time_review($this->isPC, true);
        else
            return $this->can_review_any();
    }

    function canReview($prow, $rrow, &$whyNot = null, $submit = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	assert(!$rrow || $rrow->paperId == $prow->paperId);
        $forceShow = self::override_conflict();
        $admin = $this->allowAdminister($prow);
	$manager = $this->canAdminister($prow);
	$rrow_contactId = 0;
	if ($rrow) {
	    $myReview = $manager || $this->ownReview($rrow);
	    if (isset($rrow->reviewContactId))
		$rrow_contactId = $rrow->reviewContactId;
	    else if (isset($rrow->contactId))
		$rrow_contactId = $rrow->contactId;
	} else
	    $myReview = $prow->myReviewType > 0;
	// policy
	if (($prow->timeSubmitted > 0 || $myReview
	     || ($manager && $forceShow))
	    && (($this->isPC && $prow->conflictType == 0 && !$rrow)
		|| $myReview || ($manager && $forceShow))
	    && (($myReview && $Conf->time_review($this->isPC, true))
                || (!$myReview && $this->can_review_any())
		|| ($manager && (!$submit || self::override_deadlines()))))
	    return true;
	// collect failure reasons
	// The "reviewNotAssigned" and "deadline" failure reasons are special.
	// If either is set, the system will still allow review form download.
	if ($rrow && $rrow_contactId != $this->contactId && !$admin)
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflictType > 0 && !($manager && $forceShow))
		$whyNot['conflict'] = 1;
	    else if ($this->isPC && $prow->myReviewType <= 0
		     && !$this->can_review_any()
		     && (!$rrow || $rrow_contactId == $this->contactId))
		$whyNot['reviewNotAssigned'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($admin && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($admin && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canSubmitReview($prow, $rrow, &$whyNot = null) {
	return $this->canReview($prow, $rrow, $whyNot, true);
    }

    function canRateReview($prow, $rrow) {
	global $Conf;
	$rs = $Conf->setting("rev_ratings");
	if ($rs == REV_RATINGS_PC)
	    return $this->actPC($prow);
	else if ($rs == REV_RATINGS_PC_EXTERNAL)
	    return $this->actPC($prow)
		|| ($prow->conflictType <= 0 && $prow->myReviewType > 0);
	else
	    return false;
    }

    function canSetRank($prow, $forceShow = null) {
	global $Conf;
	return $Conf->setting("tag_rank")
	    && ($this->actPC($prow, $forceShow)
		|| ($prow->conflictType <= 0 && $prow->myReviewType > 0));
    }


    function canComment($prow, $crow, &$whyNot = null, $submit = false) {
	global $Conf;
        // load comment type
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
	// check whether this is a response
	if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
	    return $this->canRespond($prow, $crow, $whyNot, $submit);
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	$forceShow = self::override_conflict();
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->timeSubmitted > 0 || $prow->myReviewType > 0)
	    && (($admin && ($forceShow || $prow->conflictType == 0))
		|| ($this->isPC && $prow->conflictType == 0)
		|| $prow->myReviewType > 0)
	    && ($Conf->time_review($this->isPC, true)
		|| $Conf->setting('cmt_always') > 0
		|| ($admin && (!$submit || self::override_deadlines())))
	    && (!$crow
		|| $crow->contactId == $this->contactId
		|| $admin))
	    return true;
	// collect failure reasons
	if ($crow && $crow->contactId != $this->contactId && !$admin)
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflictType > 0)
		$whyNot['conflict'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($admin && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($admin && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canSubmitComment($prow, $crow, &$whyNot = null) {
	return $this->canComment($prow, $crow, $whyNot, true);
    }

    function canViewComment($prow, $crow, $forceShow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $forceShow = self::override_conflict($forceShow);
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
        $ctype = $crow ? $crow->commentType : COMMENTTYPE_AUTHOR;
	$crow_contactId = 0;
	if ($crow && isset($crow->commentContactId))
	    $crow_contactId = $crow->commentContactId;
	else if ($crow)
	    $crow_contactId = $crow->contactId;
	if ($crow && isset($crow->threadContacts)
	    && isset($crow->threadContacts[$this->contactId]))
	    $thread_contactId = $this->contactId;
        $admin = $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if ($crow_contactId == $this->contactId		// wrote this comment
	    || ($conflictType >= CONFLICT_AUTHOR	// author
                && $ctype >= COMMENTTYPE_AUTHOR
                && (($ctype & COMMENTTYPE_RESPONSE)	// author's response
                    || ($Conf->timeAuthorViewReviews()  // author-visible cmt
                        && !($ctype & COMMENTTYPE_DRAFT))))
            || ($admin					// chair privilege
                && ($conflictType == 0 || $forceShow))
            || ($conflictType == 0
                && !($ctype & COMMENTTYPE_DRAFT)
                && $this->canViewReview($prow, null, $forceShow)
                && (($this->isPC && !$Conf->setting("pc_seeblindrev"))
                    || (defval($prow, "myReviewType") > 0
			&& (defval($prow, "myReviewSubmitted") > 0
			    || defval($prow, "myReviewNeedsSubmit", 1) == 0)))
                && ($this->is_core_pc()
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER)))
	    return true;
	// collect failure reasons
	if (($conflictType < CONFLICT_AUTHOR
	     && !$this->isPC && $prow->myReviewType <= 0)
	    || (!$admin && ($ctype & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_ADMINONLY))
	    $whyNot["permission"] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR)
	    $whyNot["deadline"] = 'au_seerev';
	else if ($conflictType > 0)
	    $whyNot["conflict"] = 1;
	else if ($prow->myReviewType > 0
                 && !$this->is_core_pc()
                 && defval($prow, "myReviewSubmitted") > 0)
	    $whyNot["externalReviewer"] = 1;
	else if ($ctype & COMMENTTYPE_DRAFT)
	    $whyNot["responseNotReady"] = 1;
	else
	    $whyNot["reviewNotComplete"] = 1;
	if ($admin)
	    $whyNot["forceShow"] = 1;
	return false;
    }

    function canRespond($prow, $crow, &$whyNot = null, $submit = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	$forceShow = self::override_conflict();
        $admin = $this->allowAdminister($prow);
	// policy
	if ($prow->timeSubmitted > 0
	    && (($admin && ($forceShow || $prow->conflictType == 0))
		|| $prow->conflictType >= CONFLICT_AUTHOR)
	    && ($Conf->timeAuthorRespond()
		|| ($admin && (!$submit || self::override_deadlines())))
	    && (!$crow || ($crow->commentType & COMMENTTYPE_RESPONSE)))
	    return true;
	// collect failure reasons
	if (!$admin && $prow->conflictType < CONFLICT_AUTHOR)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    $whyNot['deadline'] = "resp_done";
	    if ($admin && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($admin && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canViewCommentReviewWheres() {
	global $Conf;
	if ($this->privChair
	    || ($this->is_core_pc() && $Conf->setting("pc_seeallrev") > 0))
	    return array();
	else
	    return array("(" . $this->actAuthorSql("PaperConflict")
			 . " or MyPaperReview.reviewId is not null)");
    }


    function amPaperAuthor($paperId, $prow = null) {
	global $Conf;
	if ($prow === null) {
	    // Query for a specific match of the author and paper
	    $query = "select paperId from PaperConflict where paperId=$paperId and contactId=$this->contactId and conflictType>=" . CONFLICT_AUTHOR;
	    $result = $Conf->qe($query);
	    return edb_nrows($result) > 0;
	} else
	    return $prow->conflictType >= CONFLICT_AUTHOR;
    }

    function amDiscussionLead($paperId, $prow = null) {
	global $Conf;
	if ($prow === null && $paperId <= 0) {
	    if (!isset($this->is_lead_)) {
		$result = $Conf->qe("select paperId from Paper where leadContactId=$this->contactId limit 1");
                $this->is_lead_ = edb_nrows($result) > 0;
	    }
	    return $this->is_lead_;
	} else if ($prow === null) {
	    $result = $Conf->qe("select paperId from Paper where paperId=$paperId and leadContactId=$this->contactId");
	    return edb_nrows($result) > 0;
	} else
	    return $prow->leadContactId == $this->contactId;
    }

    function canEditContactAuthors($prow) {
	return $prow->conflictType >= CONFLICT_AUTHOR
            || $this->allowAdminister($prow);
    }

    function canViewReviewerIdentity($prow, $rrow, $forceShow = null) {
	global $Conf;
        $forceShow = self::override_conflict($forceShow);
	$rrow_contactId = 0;
	if ($rrow && isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if ($rrow && isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	// If $prow === true, be as permissive as possible: return true
	// iff there could exist a paper for which canViewReviewerIdentity
	// is true.
	if ($prow === true)
	    $prow = (object) array("conflictType" => 0, "managerContactId" => 0,
			"myReviewType" => ($this->is_reviewer() ? 1 : 0),
			"myReviewSubmitted" => 1,
			"paperId" => 1, "timeSubmitted" => 1);
	$conflictType = $this->actConflictType($prow);
        $admin = $this->allowAdminister($prow);
	if (($admin && $forceShow)
	    || ($rrow && $rrow_contactId == $this->contactId)
	    || ($rrow && $this->ownReview($rrow))
	    || ($admin && $prow && $conflictType == 0)
	    || ($this->is_core_pc()
                && $prow && $conflictType == 0
		&& (!($pc_seeblindrev = $Conf->setting("pc_seeblindrev"))
		    || ($pc_seeblindrev == 2
			&& $this->canViewReview($prow, $rrow, $forceShow))))
	    || ($prow && $prow->myReviewType > 0
		&& (defval($prow, "myReviewSubmitted") > 0
		    || defval($prow, "myReviewNeedsSubmit", 1) == 0)
		&& ($this->isPC || $Conf->settings["extrev_view"] >= 2))
	    || !reviewBlind($rrow))
	    return true;
	return false;
    }

    function canViewDiscussionLead($prow, $forceShow) {
        if ($prow === null || $prow === true)
            return $this->canViewReviewerIdentity(true, null, $forceShow);
        $forceShow = self::override_conflict($forceShow);
	$conflictType = $this->actConflictType($prow);
        $admin = $this->allowAdminister($prow);
        return ($admin && ($conflictType == 0 || $forceShow))
            || $prow->leadContactId == $this->contactId
            || (($this->isPC || $prow->myReviewType > 0)
                && $conflictType == 0
                && $this->canViewReviewerIdentity($prow, null, $forceShow));
    }

    function canViewCommentIdentity($prow, $crow, $forceShow) {
	global $Conf;
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
	if ($crow->commentType & COMMENTTYPE_RESPONSE)
	    return $this->canViewAuthors($prow, $forceShow);
        $forceShow = self::override_conflict($forceShow);
	$crow_contactId = 0;
	if ($crow && isset($crow->commentContactId))
	    $crow_contactId = $crow->commentContactId;
	else if ($crow)
	    $crow_contactId = $crow->contactId;
	$conflictType = $this->actConflictType($prow);
        $admin = $this->allowAdminister($prow);
	if (($admin && $forceShow)
	    || $crow_contactId == $this->contactId
	    || ($this->isPC && $conflictType == 0)
	    || ($prow->myReviewType > 0 && $conflictType == 0
		&& $Conf->settings["extrev_view"] >= 2)
            || ($br = $Conf->blindReview()) == BLIND_NEVER
            || ($br == BLIND_OPTIONAL && $crow
                && !($crow->commentType & COMMENTTYPE_BLIND)))
	    return true;
	return false;
    }

    function canViewDecision($prow, $forceShow = null) {
	global $Conf;
	$conflictType = $prow ? $this->actConflictType($prow) : 0;
        if ($this->canAdminister($prow, $forceShow)
            || ($prow && $conflictType >= CONFLICT_AUTHOR
                && $Conf->timeAuthorViewDecision())
            || ($this->isPC
                && $Conf->timePCViewDecision($prow && $conflictType > 0))
	    || ($prow && defval($prow, "myReviewType", 0) > 0
		&& defval($prow, "myReviewSubmitted", 0) > 0
		&& $Conf->timeReviewerViewDecision()))
	    return true;
	return false;
    }

    function viewReviewFieldsScore($prow, $rrow) {
	// Returns the maximum authorView score for an invisible review
	// field.  Values for authorView are:
	//   VIEWSCORE_ADMINONLY     -2   admin can view
	//   VIEWSCORE_REVIEWERONLY  -1   admin and review author can view
	//   VIEWSCORE_PC             0   admin and PC/any reviewer can view
	//   VIEWSCORE_AUTHOR         1   admin and PC/any reviewer and author can view
	// So returning -3 means all scores are visible.
	// Deadlines are not considered.
	// (!$prow && !$rrow) ==> return best case scores that can be seen.
	// (!$prow &&  $rrow) ==> return worst case scores that can be seen.
        // ** See also canViewReview.

	// chair can see everything
	if ($this->canAdminister($prow))
	    return VIEWSCORE_ADMINONLY - 1;

	// author can see author information
	if (($prow && $this->actConflictType($prow) >= CONFLICT_AUTHOR)
	    || (!$prow && !$this->is_reviewer()))
	    return VIEWSCORE_AUTHOR - 1;

	// authors and external reviewers of not this paper can't see anything
	if (!$this->is_reviewer()
	    || (!$this->isPC && $prow && $prow->myReviewType <= 0))
	    return 10000;

	// see who this reviewer is
	if (!$rrow)
	    $rrowContactId = $this->contactId;
	else if (isset($rrow->reviewContactId))
	    $rrowContactId = $rrow->reviewContactId;
	else if (isset($rrow->contactId))
	    $rrowContactId = $rrow->contactId;
	else
	    $rrowContactId = -1;

	// reviewer can see any information they entered
	if ($rrowContactId == $this->contactId)
	    return VIEWSCORE_REVIEWERONLY - 1;

	// otherwise, can see information visible for all reviewers
	return VIEWSCORE_PC - 1;
    }

    function canViewTags($prow, $forceShow = null) {
        // see also PaperActions::all_tags
	global $Conf;
	return $this->isPC
            && (!$prow || $prow->conflictType <= 0
                || $this->canAdminister($prow, $forceShow)
                || $Conf->setting("tag_seeall") > 0);
    }

    function canSetTags($prow, $forceShow = null) {
	return $this->isPC
            && (!$prow || $prow->conflictType <= 0
                || $this->canAdminister($prow, $forceShow));
    }

    function canSetOutcome($prow) {
        return $this->canAdminister($prow);
    }


    function deadlines() {
	// Return cleaned deadline-relevant settings that this user can see.
	global $Conf;
	$dlx = $Conf->deadlines();
	$now = $dlx["now"];
	$dl = array("now" => $now);
	foreach (array("sub_open", "resp_open", "rev_open", "final_open") as $x)
	    $dl[$x] = $dlx[$x] > 0;

	if ($dlx["sub_reg"] && $dlx["sub_reg"] != $dlx["sub_update"])
	    $dl["sub_reg"] = $dlx["sub_reg"];
	if ($dlx["sub_update"] && $dlx["sub_update"] != $dlx["sub_sub"])
	    $dl["sub_update"] = $dlx["sub_update"];
	$dl["sub_sub"] = $dlx["sub_sub"];

	$dl["resp_done"] = $dlx["resp_done"];

	$dl["rev_open"] = $dl["rev_open"] && $this->is_reviewer();
	if ($this->isPC) {
	    if ($dlx["pcrev_soft"] > $now)
		$dl["pcrev_done"] = $dlx["pcrev_soft"];
	    else if ($dlx["pcrev_hard"]) {
		$dl["pcrev_done"] = $dlx["pcrev_hard"];
		$dl["pcrev_ishard"] = true;
	    }
	}
	if ($this->is_reviewer()) {
	    if ($dlx["extrev_soft"] > $now)
		$dl["extrev_done"] = $dlx["extrev_soft"];
	    else if ($dlx["extrev_hard"]) {
		$dl["extrev_done"] = $dlx["extrev_hard"];
		$dl["extrev_ishard"] = true;
	    }
	}

	if ($dl["final_open"]) {
	    if ($dlx["final_soft"] > $now)
		$dl["final_done"] = $dlx["final_soft"];
	    else {
		$dl["final_done"] = $dlx["final_done"];
		$dl["final_ishard"] = true;
	    }
	}

	// mark grace periods
	foreach (array("sub" => array("sub_reg", "sub_update", "sub_sub"),
		       "resp" => array("resp_done"),
		       "rev" => array("pcrev_done", "extrev_done"),
		       "final" => array("final_done")) as $type => $dlnames) {
	    if ($dl["${type}_open"] && ($grace = $dlx["${type}_grace"])) {
		foreach ($dlnames as $dlname)
		    // Give a minute's notice that there will be a grace
		    // period to make the UI a little better.
		    if (defval($dl, $dlname) && $dl[$dlname] + 60 < $now
			&& $dl[$dlname] + $grace >= $now)
			$dl["${dlname}_ingrace"] = true;
	    }
	}

        // add meeting navigation
        if ($this->is_core_pc() && $Conf->setting("meeting_nav")
            && ($navstatus = MeetingNavigator::status($this)))
            $dl["nav"] = $navstatus;

        return $dl;
    }


    function paperStatus($paperId, $row, $long) {
	global $Conf, $paperStatusCache;
	if ($row->timeWithdrawn > 0)
	    return "<span class='pstat pstat_with'>Withdrawn</span>";
	else if ($row->timeSubmitted <= 0 && $row->paperStorageId == 1)
	    return "<span class='pstat pstat_noup'>No submission</span>";
	else if (isset($row->outcome) && $row->outcome != 0
		 && $this->canViewDecision($row, abs($long) > 1)) {
	    if (!isset($paperStatusCache) || !$paperStatusCache)
		$paperStatusCache = array();
	    if (!isset($paperStatusCache[$row->outcome])) {
		$data = "<span class=\"pstat "
		    . ($row->outcome > 0 ? "pstat_decyes" : "pstat_decno");

		$outcomes = $Conf->outcome_map();
		$decname = @$outcomes[$row->outcome];
		if ($decname) {
		    $trdecname = preg_replace('/[^-.\w]/', '', $decname);
		    if ($trdecname != "")
			$data .= " pstat_" . strtolower($trdecname);
		    $data .= "\">" . htmlspecialchars($decname) . "</span>";
		} else
		    $data .= "\">Unknown decision #" . htmlspecialchars($row->outcome) . "</span>";

		$paperStatusCache[$row->outcome] = $data;
	    }
	    return $paperStatusCache[$row->outcome];
	//} else if (isset($row->reviewCount) && $row->reviewCount > 0) {
	//    if ($long < 0 && $row->conflictType < CONFLICT_AUTHOR)
	//	return "";
	//    else if ($this->canViewReview($row, null, null))
	//	return "<span class='pstat pstat_rev'>Reviews&nbsp;available</span>";
	//    else
	//	return "<span class='pstat pstat_rev'>Under&nbsp;review</span>";
	} else {
	    if ($row->timeSubmitted > 0) {
		if ($long < 0)
		    return "";
		$x = "<span class='pstat pstat_sub'>Submitted</span>";
	    } else
		$x = "<span class='pstat pstat_prog'>Not ready</span>";
	    return $x;
	}
    }


    public static function password_hmac_key($keyid, $create) {
        global $Conf, $Opt;
        if ($keyid === null)
            $keyid = defval($Opt, "passwordHmacKeyid", 0);
        if ($keyid == 0 && isset($Opt["passwordHmacKey"]))
            $key = $Opt["passwordHmacKey"];
        else if (isset($Opt["passwordHmacKey.$keyid"]))
            $key = $Opt["passwordHmacKey.$keyid"];
        else {
            $key = $Conf->setting_data("passwordHmacKey.$keyid", "");
            if ($key == "" && $create) {
                $key = hotcrp_random_bytes(24);
                $Conf->save_setting("passwordHmacKey.$keyid", time(), $key);
            }
        }
        if ($create)
            return array($keyid, $key);
        else
            return $key;
    }

    public function check_password($password) {
        global $Conf, $Opt;
        assert(!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]));
        if ($password == "")
            return false;
        if ($this->password_type == 0)
            return $password == $this->password;
        if ($this->password_type == 1
            && ($hash_method_pos = strpos($this->password, " ", 1)) !== false
            && ($keyid_pos = strpos($this->password, " ", $hash_method_pos + 1)) !== false
            && strlen($this->password) > $keyid_pos + 17
            && function_exists("hash_hmac")) {
            $hash_method = substr($this->password, 1, $hash_method_pos - 1);
            $keyid = substr($this->password, $hash_method_pos + 1, $keyid_pos - $hash_method_pos - 1);
            $salt = substr($this->password, $keyid_pos + 1, 16);
            return hash_hmac($hash_method, $salt . $password,
                             self::password_hmac_key($keyid, false), true)
                == substr($this->password, $keyid_pos + 17);
        } else if ($this->password_type == 1)
            error_log("cannot check hashed password for user " . $this->email);
        return false;
    }

    static public function password_hash_method() {
        global $Opt;
        if (isset($Opt["passwordHashMethod"]) && $Opt["passwordHashMethod"])
            return $Opt["passwordHashMethod"];
        else
            return PHP_INT_SIZE == 8 ? "sha512" : "sha256";
    }

    public function password_needs_upgrade() {
        global $Opt;
        if ((@$Opt["safePasswords"] && $this->visits == 0)
            || (is_int(@$Opt["safePasswords"])
                && $Opt["safePasswords"] > 1)) {
            $expected_prefix = " " . self::password_hash_method()
                . " " . defval($Opt, "passwordHmacKeyid", 0) . " ";
            return $this->password_type == 0
                || ($this->password_type == 1 && !str_starts_with($this->password, $expected_prefix));
        } else
            return false;
    }

    public function change_password($new_password) {
        global $Conf, $Opt;
        $this->password_plaintext = $new_password;
        if ($this->password_needs_upgrade())
            $this->password_type = 1;
        if ($this->password_type == 1 && function_exists("hash_hmac")) {
            list($keyid, $key) = self::password_hmac_key(null, true);
            $hash_method = self::password_hash_method();
            $salt = hotcrp_random_bytes(16);
            $this->password = " " . $hash_method . " " . $keyid . " " . $salt
                . hash_hmac($hash_method, $salt . $new_password, $key, true);
        } else {
            $this->password = $new_password;
            $this->password_type = 0;
        }
    }

    static function random_password($length = 14) {
	global $Opt;
	if (isset($Opt["ldapLogin"]))
	    return "<stored in LDAP>";
	else if (isset($Opt["httpAuthLogin"]))
	    return "<using HTTP authentication>";

        // see also regexp in randompassword.php
	$l = explode(" ", "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =");
	$n = count($l);

	$bytes = hotcrp_random_bytes($length + 10, true);
	if (!$bytes) {
	    $bytes = "";
	    while (strlen($bytes) < $length)
		$bytes .= sha1($Opt["conferenceKey"] . pack("V", mt_rand()));
	}

	$pw = "";
        $nvow = 0;
	for ($i = 0;
             $i < strlen($bytes) &&
                 strlen($pw) < $length + max(0, ($nvow - 3) / 3);
             ++$i) {
            $x = ord($bytes[$i]) % $n;
            if ($x < 30)
                ++$nvow;
	    $pw .= $l[$x];
        }
	return $pw;
    }

    function sendAccountInfo($create, $sensitive) {
	global $Conf, $Opt;
        $rest = array();
        if ($create)
            $template = "@createaccount";
        else if ($this->password_type == 0)
            $template = "@accountinfo";
        else {
            $rest["capability"] = $Conf->create_capability(CAPTYPE_RESETPASSWORD, array("contactId" => $this->contactId, "timeExpires" => time() + 259200));
            $Conf->log("Created password reset request", $this);
            $template = "@resetpassword";
        }

	$prep = Mailer::prepareToSend($template, null, $this, null, $rest);
	if ($prep["allowEmail"] || !$sensitive
            || @$Opt["debugShowSensitiveEmail"]) {
            Mailer::sendPrepared($prep);
	    return $template;
	} else {
	    $Conf->errorMsg("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
	    return false;
	}
    }


    function assign_paper($pid, $rrow, $reviewer_cid, $type, $when) {
	global $Conf, $reviewTypeName;
	if ($type <= 0 && $rrow && $rrow->reviewType && $rrow->reviewModified) {
            if ($rrow->reviewType >= REVIEW_SECONDARY)
                $type = REVIEW_PC;
            else
                return;
        }
	$qtag = "";
	if ($type > 0 && (!$rrow || !$rrow->reviewType)) {
	    $qa = $qb = "";
	    if (($type == REVIEW_PRIMARY || $type == REVIEW_SECONDARY)
		&& ($t = $Conf->setting_data("rev_roundtag"))) {
		if (!($k = array_search($t, $Conf->settings["rounds"]))) {
		    $rounds = $Conf->setting_data("tag_rounds", "");
		    $rounds = ($rounds ? "$rounds$t " : " $t ");
		    $Conf->qe("insert into Settings (name, value, data) values ('tag_rounds', 1, '" . sqlq($rounds) . "') on duplicate key update data='" . sqlq($rounds) . "'");
		    $Conf->settings["tag_rounds"] = 1;
		    $Conf->settingTexts["tag_rounds"] = $rounds;
		    $Conf->settings["rounds"][] = $t;
		    $k = array_search($t, $Conf->settings["rounds"]);
		}
		$qa .= ", reviewRound";
		$qb .= ", $k";
	    }
	    if ($Conf->sversion >= 46) {
		$qa .= ", timeRequested";
		$qb .= ", " . $when;
	    }
	    $q = "insert into PaperReview (paperId, contactId, reviewType, requestedBy$qa) values ($pid, $reviewer_cid, $type, $this->contactId$qb)";
	} else if ($type > 0 && $rrow->reviewType != $type)
	    $q = "update PaperReview set reviewType=$type where reviewId=$rrow->reviewId";
	else if ($type <= 0 && $rrow && $rrow->reviewType)
	    $q = "delete from PaperReview where reviewId=$rrow->reviewId";
        else
	    return;

	if ($Conf->qe($q, "while assigning review")) {
	    if ($qtag)
		$Conf->q($qtag);
	    if ($rrow && defval($rrow, "reviewToken", 0) != 0 && $type <= 0)
		$Conf->settings["rev_tokens"] = -1;
            if ($q[0] == "d")
                $msg = "Removed " . $reviewTypeName[$rrow->reviewType] . " review";
            else if ($q[0] == "u")
                $msg = "Changed " . $reviewTypeName[$rrow->reviewType] . " review to " . $reviewTypeName[$type];
            else
                $msg = "Added " . $reviewTypeName[$type] . " review";
	    $Conf->log($msg . " by " . $this->email, $reviewer_cid, $pid);
	    if ($q[0] == "i")
		$Conf->qx("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
	    if ($q[0] == "i" && $type >= REVIEW_PC && $Conf->setting("pcrev_assigntime", 0) < $when)
		$Conf->save_setting("pcrev_assigntime", $when);
	}
    }

}