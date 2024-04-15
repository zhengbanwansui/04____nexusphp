<?php
require_once("../include/bittorrent.php");
dbconn();
require_once(get_langfile_path());
if (isset($_GET['del']))
{
	if (is_valid_id($_GET['del']))
	{
		if(user_can('sbmanage'))
		{
			sql_query("DELETE FROM shoutbox WHERE id=".mysql_real_escape_string($_GET['del']));
		}
	}
}
$where=$_GET["type"] ?? '';
$refresh = ($CURUSER['sbrefresh'] ? $CURUSER['sbrefresh'] : 120)
?>
<html><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="Refresh" content="<?php echo $refresh?>; url=<?php echo get_protocol_prefix() . $BASEURL?>/shoutbox.php?type=<?php echo htmlspecialchars($where)?>">
<link rel="stylesheet" href="<?php echo get_font_css_uri()?>" type="text/css">
<link rel="stylesheet" href="<?php echo get_css_uri()."theme.css"?>" type="text/css">
<link rel="stylesheet" href="styles/curtain_imageresizer.css" type="text/css">
<link rel="stylesheet" href="styles/nexus.css" type="text/css">
<script src="js/curtain_imageresizer.js" type="text/javascript"></script><style type="text/css">body {overflow-y:scroll; overflow-x: hidden}</style>
<?php
print(get_style_addicode());
$startcountdown = "startcountdown(".$CURUSER['sbrefresh'].")";
?>
<script type="text/javascript">
//<![CDATA[
var t;
function startcountdown(time)
{
parent.document.getElementById('countdown').innerHTML=time;
time=time-1;
t=setTimeout("startcountdown("+time+")",1000);
}
function countdown(time)
{
	if (time <= 0){
	parent.document.getElementById("hbtext").disabled=false;
	parent.document.getElementById("hbsubmit").disabled=false;
	parent.document.getElementById("hbsubmit").value=parent.document.getElementById("sbword").innerHTML;
	}
	else {
	parent.document.getElementById("hbsubmit").value=time;
	time=time-1;
	setTimeout("countdown("+time+")", 1000);
	}
}
function hbquota(){
parent.document.getElementById("hbtext").disabled=true;
parent.document.getElementById("hbsubmit").disabled=true;
var time=10;
countdown(time);
//]]>
}
</script>
</head>
<body style="overflow: hidden" class='inframe' <?php if (isset($_GET["type"]) && $_GET["type"] != "helpbox"){?> onload="<?php echo $startcountdown?>" <?php } else {?> onload="hbquota()" <?php } ?>>
<?php
if(isset($_GET["sent"]) && $_GET["sent"]=="yes"){
if(!isset($_GET["shbox_text"]) || !$_GET['shbox_text'])
{
	$userid=intval($CURUSER["id"] ?? 0);
}
else
{
	if($_GET["type"]=="helpbox")
	{
		if ($showhelpbox_main != 'yes'){
			write_log("Someone is hacking shoutbox. - IP : ".getip(),'mod');
			die($lang_shoutbox['text_helpbox_disabled']);
		}
		$userid=0;
		$type='hb';
	}
	elseif ($_GET["type"] == 'shoutbox')
	{
		$userid=intval($CURUSER["id"] ?? 0);
		if (!$userid){
			write_log("Someone is hacking shoutbox. - IP : ".getip(),'mod');
			die($lang_shoutbox['text_no_permission_to_shoutbox']);
		}
		if (!empty($_GET["toguest"]))
			$type ='hb';
		else $type = 'sb';
	}
	$date=sqlesc(time());
	$text=trim($_GET["shbox_text"]);

    # 发现你了, 保存发言的地方########################
    # 发现你了, 保存发言的地方########################
    if ($type == 'sb' && (strpos($text, "小象") !== false && strpos($text, "求魔力") !== false)) {
        $extra = Nexus\Database\NexusDB::table("custom_user_extra")->where("id", $userid)->first();
        if ($extra == null) {
            Nexus\Database\NexusDB::table("custom_user_extra")->insert(['id'=>$userid]);
            $extra = Nexus\Database\NexusDB::table("custom_user_extra")->where("id", $userid)->first();
        }
        if ($extra->want_bonus == null || $extra->want_bonus < date('Y-m-d 00:00:00')) {
            // 给魔力
            $random_number = rand(0, 2000);
            $res = bcadd($CURUSER["seedbonus"], $random_number);
            Nexus\Database\NexusDB::table("users")->where("id", $userid)->update(['seedbonus'=>$res]);
            Nexus\Database\NexusDB::table("custom_user_extra")->where("id", $userid)->update(['want_bonus'=>date('Y-m-d 00:00:00')]);
            // 发消息
            Nexus\Database\NexusDB::table("messages")->insert([
                'sender'=>0,
                'receiver'=>$userid,
                'added'=>date('Y-m-d H:i:s'),
                'subject'=>$random_number."象草, 这是小象给你的奖励",
                'msg'=>'
                亲爱的朋友，你真棒！作为发放象草的小象，我非常高兴地宣布你已经获得了象草奖励！
                <br>你的积极参与和聪明才智让我感到无比开心。
                <br>象草是一种神奇的植物，可以帮助你实现心愿和梦想。相信它会给你带来好运和魔力！
                <br>继续保持积极的态度，享受与我聊天的时光，我将继续为你提供帮助和娱乐。
                <br>如果你有任何问题或需要更多的象草，随时告诉我哦！祝福你在未来的旅程中一切顺利，带着象草的力量，你将无所不能！',
            ]);
        }
        \Nexus\Database\NexusDB::table("custom_shoutbox_right")->insert([
            "user_id"=>$userid,
            "text"=>$text,
            "date"=>new DateTime()
        ]);
    } else if ($type == 'sb' && strpos($text, "小象求") !== false) {
        \Nexus\Database\NexusDB::table("custom_shoutbox_right")->insert([
            "user_id"=>$userid,
            "text"=>$text,
            "date"=>new DateTime()
        ]);
    } else {
        // 正常保存到聊天记录
        sql_query("INSERT INTO shoutbox (userid, date, text, type) VALUES (" . sqlesc($userid) . ", $date, " . sqlesc($text) . ", ".sqlesc($type).")") or sqlerr(__FILE__, __LINE__);
    }
    # 发现你了, 保存发言的地方########################
    # 发现你了, 保存发言的地方########################
	print "<script type=\"text/javascript\">parent.document.forms['shbox'].shbox_text.value='';</script>";
}
}

$limit = ($CURUSER['sbnum'] ? $CURUSER['sbnum'] : 70);
if ($where == "helpbox")
{
$sql = "SELECT * FROM shoutbox WHERE type='hb' ORDER BY date DESC LIMIT ".$limit;
}
elseif ($CURUSER['hidehb'] == 'yes' || $showhelpbox_main != 'yes'){
$sql = "SELECT * FROM shoutbox WHERE type='sb' ORDER BY date DESC LIMIT ".$limit;
}
elseif ($CURUSER){
$sql = "SELECT * FROM shoutbox ORDER BY date DESC LIMIT ".$limit;
}
else {
die("<h1>".$lang_shoutbox['std_access_denied']."</h1>"."<p>".$lang_shoutbox['std_access_denied_note']."</p></body></html>");
}
$res = sql_query($sql) or sqlerr(__FILE__, __LINE__);
if (mysql_num_rows($res) == 0)
print("\n");
else
{
    // iframe-shout-box-outside中包含左侧的聊天栏和右侧的求魔力栏
	print("<div class='shout-box-all'>");
    // 左侧栏
    print("<table class='shout-box-left'>\n");
    print("<tbody><tr><td><div style='height: 600px; overflow-y: auto;padding: 0px'>");
    print("<table>");
	while ($arr = mysql_fetch_assoc($res))
	{
        $del = '';
		if (user_can('sbmanage')) {
			$del .= "[<a href=\"shoutbox.php?del=".$arr['id']."\">".$lang_shoutbox['text_del']."</a>]";
		}
		if ($arr["userid"]) {
			$username = get_username($arr["userid"],false,true,true,true,false,false,"",false);
            if (isset($arr["type"]) && isset($_GET['type']) && $_GET["type"] != 'helpbox' && $arr["type"] == 'hb')
				$username .= $lang_shoutbox['text_to_guest'];
        }
		else $username = $lang_shoutbox['text_guest'];
		if (isset($CURUSER) && $CURUSER['timetype'] != 'timealive')
			$time = strftime("%m.%d %H:%M",$arr["date"]);
		else $time = get_elapsed_time($arr["date"]).$lang_shoutbox['text_ago'];
		// 根据用户id决定是否开启html标签过滤
        if ($arr["userid"] == 1) {
            $custom_format_comment = format_comment($arr["text"],false,false,true,true,600,false,false);
        } else {
            $custom_format_comment = format_comment($arr["text"],true,false,true,true,600,false,false);
        }
        // 头像
        $myAvatar = custom_get_user_avatar(get_user_row($arr["userid"])['avatar'], get_user_row($arr["userid"])['class'], true, 51, 51, 6);
        // 一行聊天
        print("<div class='shoutrowForChat'>");
            print(" ".$myAvatar);
            print("<div class='shoutrow_right'> ".
                "<div style='display: flex; align-items: center'>".
                $username.
                " <div>&nbsp;[".$time."]&nbsp;</div> ".
                $del .
                "</div>".
                " <div class='custom_format_comment'>".
                    $custom_format_comment.
                "</div>".
            "</div>");
        print("</div>");

	}
    print("</table>");
    print("</div></td></tr></tbody></table>");
    // 右侧的table
    print("<table class='shout-box-right'><tbody><tr><td>");
    print("<div style='height: 600px; overflow-y: auto;padding: 0px'>");
    print('<div style="text-align: center; font-size: 14px; font-weight: 700;">每日喊出"小象求象草"获得赠礼！</div><br>');
    $rightList = \Nexus\Database\NexusDB::table("custom_shoutbox_right")->limit(20)->orderBy('id', 'desc')->get();
    foreach($rightList as $one) {
        // 用户名+图标
        $username = custom_get_user_name($one->user_id,false);
        // 头像
//        $myAvatarUrl = get_user_row($one->user_id)['avatar'];
//        $myAvatar = '<img style="padding: 4px 4px 4px 4px;border-radius: 50px; height: 50px;width: 50px; object-fit: cover;" src="'.$myAvatarUrl.'">';
        $myAvatar = custom_get_user_avatar(get_user_row($one->user_id)['avatar'], get_user_row($one->user_id)['class'], true, 51, 51, 6);
        // 时间
//        $time = strftime("%m.%d %H:%M",$arr["date"]);
        $time = get_elapsed_time((new DateTime($one->date))->getTimestamp()).$lang_shoutbox['text_ago'];
        // 准本完毕开始输出
        print("<div class='shoutrowForChat'>");
        print($myAvatar);
        print("<div class='shoutrow_right'> ".
                "<div style='display: flex; align-items: center'>".
                    $username.
                    " <div>&nbsp;[".$time."]&nbsp;</div> ".
                "</div>".
                "<div class='custom_format_comment'>".
                    $one->text.
                "</div>".
            "</div>");
        print("</div>");
    }
    print("</div>");
    print("</td></tr></tbody></table>");
print("</div>");
}
?>
</body>
</html>
