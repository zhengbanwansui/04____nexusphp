<?php
require_once("../include/bittorrent.php");
dbconn();
require_once ROOT_PATH . get_langfile_path("functions.php");
require_once('lang/chs/lang_download.php');
function denyDownload()
{
    permissiondenied();
}
$torrentRep = new \App\Repositories\TorrentRepository();
if (!empty($_REQUEST['downhash'])) {
    $params = explode('|', $_REQUEST['downhash']);
    if (empty($params[0]) || empty($params[1])) {
        die("invalid downhash, format error");
    }
    $uid = $params[0];
    $hash = $params[1];
    $res = sql_query("SELECT * FROM users WHERE id=". sqlesc($uid)." LIMIT 1");
    $user = mysql_fetch_array($res);
    if (!$user)
        die("invalid uid");
    elseif ($user['enabled'] == 'no' || $user['parked'] == 'yes')
        die("account disabed or parked");
    $oldip = $user['ip'];
    $user['ip'] = getip();
    $CURUSER = $user;
    $decrypted = $torrentRep->decryptDownHash($hash, $user);
    if (empty($decrypted)) {
        do_log("downhash invalid: " . nexus_json_encode($_REQUEST));
        die("invalid downhash, decrpyt fail");
    }
    $id = $decrypted[0];
} elseif (get_setting('torrent.download_support_passkey') == 'yes' && !empty($_REQUEST['passkey']) && !empty($_REQUEST['id'])) {
    $res = sql_query("SELECT * FROM users WHERE passkey=". sqlesc($_REQUEST['passkey'])." LIMIT 1");
    $user = mysql_fetch_array($res);
    if (!$user)
        die("invalid passkey");
    elseif ($user['enabled'] == 'no' || $user['parked'] == 'yes')
        die("account disabed or parked");
    $oldip = $user['ip'];
    $user['ip'] = getip();
    $CURUSER = $user;
    $id = $_REQUEST['id'];
} else {
    $id = (int)$_GET["id"];
    if (!$id)
        httperr();
	loggedinorreturn();
	parked();
	$letdown = intval($_GET['letdown'] ?? 0);
	if (!$letdown && $CURUSER['showdlnotice'] == 1)
	{
		nexus_redirect(getSchemeAndHttpHost() . "/downloadnotice.php?torrentid=".$id."&type=firsttime");
	}
	elseif (!$letdown && $CURUSER['showclienterror'] == 'yes')
	{
        nexus_redirect(getSchemeAndHttpHost() . "/downloadnotice.php?torrentid=".$id."&type=client");
	}
	elseif (!$letdown && $CURUSER['leechwarn'] == 'yes')
	{
        nexus_redirect(getSchemeAndHttpHost() . "/downloadnotice.php?torrentid=".$id."&type=ratio");
	}
}
//User may choose to download torrent from RSS. So log ip changes when downloading 种子.
if ($iplog1 == "yes") {
	if (($oldip != $CURUSER["ip"]) && $CURUSER["ip"])
	sql_query("INSERT INTO iplog (ip, userid, access) VALUES (" . sqlesc($CURUSER['ip']) . ", " . $CURUSER['id'] . ", '" . $CURUSER['last_access'] . "')");
}
//User may choose to download torrent from RSS. So update his last_access and ip when downloading 种子.
sql_query("UPDATE users SET last_access = ".sqlesc(date("Y-m-d H:i:s")).", ip = ".sqlesc($CURUSER['ip'])."  WHERE id = ".sqlesc($CURUSER['id']));

/*
@ini_set('zlib.output_compression', 'Off');
@set_time_limit(0);

if (@ini_get('output_handler') == 'ob_gzhandler' AND @ob_get_length() !== false)
{	// if output_handler = ob_gzhandler, turn it off and remove the header sent by PHP
	@ob_end_clean();
	header('Content-Encoding:');
}
*/

if ($CURUSER['downloadpos']=="no") {
    denyDownload();
}

$trackerSchemaAndHost = get_tracker_schema_and_host();
$ssl_torrent = $trackerSchemaAndHost['ssl_torrent'];
$base_announce_url = $trackerSchemaAndHost['base_announce_url'];

$res = sql_query("SELECT torrents_tmp.name, torrents_tmp.filename, torrents_tmp.save_as, torrents_tmp.size, torrents_tmp.owner, torrents_tmp.banned, torrents_tmp.approval_status, torrents_tmp.price, categories.mode as search_box_id FROM torrents_tmp left join categories on torrents_tmp.category = categories.id WHERE torrents_tmp.id = ".sqlesc($id)) or sqlerr(__FILE__, __LINE__);
$row = mysql_fetch_assoc($res);
if (!$row) {
    do_log("[TORRENT_NOT_EXISTS_IN_DATABASE] $id", 'error');
    httperr();
}
$fn = getFullDirectory("$torrent_dir/$id.torrent");
if (!is_file($fn)) {
    do_log("[TORRENT_NOT_EXISTS_IN_PATH] $fn",'error');
    httperr();
}
if (!is_readable($fn)) {
    do_log("[TORRENT_NOT_READABLE] $fn",'error');
    httperr();
}
if (filesize($fn) == 0) {
    do_log("[TORRENT_NOT_VALID_SIZE_ZERO] $fn",'error');
    httperr();
}

$approvalNotAllowed = $row['approval_status'] != \App\Models\Torrent::APPROVAL_STATUS_ALLOW && get_setting('torrent.approval_status_none_visible') == 'no';
$allowOwnerDownload = $row['owner'] == $CURUSER['id'];
$canSeedBanned = user_can('seebanned');
$canAccessTorrent = can_access_torrent($row, $CURUSER['id']);
if ((($row['banned'] == 'yes' || ($approvalNotAllowed && !$allowOwnerDownload)) && !$canSeedBanned) || !$canAccessTorrent) {
    do_log("[DENY_DOWNLOAD], user: {$CURUSER['id']}, approvalNotAllowed: $approvalNotAllowed, allowOwnerDownload: $allowOwnerDownload, canSeedBanned: $canSeedBanned, canAccessTorrent: $canAccessTorrent", 'error');
    denyDownload();
}

sql_query("UPDATE torrents_tmp SET hits = hits + 1 WHERE id = ".sqlesc($id)) or sqlerr(__FILE__, __LINE__);

if (strlen($CURUSER['passkey']) != 32) {
	$CURUSER['passkey'] = md5($CURUSER['username'].date("Y-m-d H:i:s").$CURUSER['passhash']);
	sql_query("UPDATE users SET passkey=".sqlesc($CURUSER['passkey'])." WHERE id=".sqlesc($CURUSER['id']));
}
$trackerReportAuthKey = $torrentRep->getTrackerReportAuthKey($id, $CURUSER['id'], true);
$dict = \Rhilip\Bencode\Bencode::load($fn);
$dict['announce'] = $ssl_torrent . $base_announce_url . "?passkey=" . $CURUSER['passkey'];
do_log(sprintf("[ANNOUNCE_URL], user: %s, torrent: %s, url: %s", $CURUSER['id'] ?? '', $id, $dict['announce']));

header("Content-Type: application/x-bittorrent");

if ( str_replace("Gecko", "", $_SERVER['HTTP_USER_AGENT']) != $_SERVER['HTTP_USER_AGENT'])
{
	header ("Content-Disposition: attachment; filename=\"$torrentnameprefix.".$row["save_as"].".torrent\" ; charset=utf-8");
}
else if ( str_replace("Firefox", "", $_SERVER['HTTP_USER_AGENT']) != $_SERVER['HTTP_USER_AGENT'] )
{
	header ("Content-Disposition: attachment; filename=\"$torrentnameprefix.".$row["save_as"].".torrent\" ; charset=utf-8");
}
else if ( str_replace("Opera", "", $_SERVER['HTTP_USER_AGENT']) != $_SERVER['HTTP_USER_AGENT'] )
{
	header ("Content-Disposition: attachment; filename=\"$torrentnameprefix.".$row["save_as"].".torrent\" ; charset=utf-8");
}
else if ( str_replace("IE", "", $_SERVER['HTTP_USER_AGENT']) != $_SERVER['HTTP_USER_AGENT'] )
{
	header ("Content-Disposition: attachment; filename=".str_replace("+", "%20", rawurlencode("$torrentnameprefix." . $row["save_as"] .".torrent")));
}
else
{
	header ("Content-Disposition: attachment; filename=".str_replace("+", "%20", rawurlencode("$torrentnameprefix." . $row["save_as"] .".torrent")));
}

\Nexus\Database\NexusDB::cache_put("authkey2passkey:$trackerReportAuthKey", $CURUSER['passkey'], 3600*24);
echo \Rhilip\Bencode\Bencode::encode($dict);
?>
