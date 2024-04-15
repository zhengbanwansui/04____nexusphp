<?php
require_once("../include/bittorrent.php");
dbconn();
loggedinorreturn();
stdhead("自定义主题配色");
?>
<script src="js/jscolor.js"></script>
<div style="width: 70%">
    <h1>自定义主题配色(修改仅支持当前客户端)</h1>
    <h2>顶部菜单配色 - 默认值 rgba(255,255,255, 1)</h2>
    <input id="mainmenuColor" value="rgba(255,255,255, 1)" data-jscolor="{}" class="jscolor" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-current-color="rgba(195,255,200,1)" style="background-image: linear-gradient(to right, rgb(195, 255, 200) 0%, rgb(195, 255, 200) 30px, rgba(0, 0, 0, 0) 31px, rgba(0, 0, 0, 0) 100%), url(&quot;data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAYCAYAAAC8/X7cAAAAAXNSR0IArs4c6QAAAI5JREFUWEftls0JwCAMRuNMbuAs4k6u4BZ6dwpn8FjaaylSDVTo4XmOgXzfy4+JMZ6ieLVWRbSItVYVv5Lfey8559R7PwwFTPTFgYFAIKTpShACoYcCK3P6/gWEQOjvCIUQVLfQDqa1PeOck1JKaq0dhgImW23H1MIBzSmBAwO1QAiEXhRY2TMgBEIfInQBukhfuKdtnJAAAAAASUVORK5CYII=&quot;) !important; background-position: left top, left top !important; background-size: auto, 32px 16px !important; background-repeat: repeat-y, repeat-y !important; background-origin: padding-box, padding-box !important; padding-left: 40px !important; font-size: 16px;">
    <input type="button" onclick="changeMainmenuColor()" value="更改">
    <h2>网站整体的背景色 - 默认值 rgba(244,248,251, 0.81)</h2>
    <input id="outerColor" value="rgba(255,255,255, 0.81)" data-jscolor="{}" class="jscolor" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-current-color="rgba(195,255,200,1)" style="background-image: linear-gradient(to right, rgb(195, 255, 200) 0%, rgb(195, 255, 200) 30px, rgba(0, 0, 0, 0) 31px, rgba(0, 0, 0, 0) 100%), url(&quot;data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAYCAYAAAC8/X7cAAAAAXNSR0IArs4c6QAAAI5JREFUWEftls0JwCAMRuNMbuAs4k6u4BZ6dwpn8FjaaylSDVTo4XmOgXzfy4+JMZ6ieLVWRbSItVYVv5Lfey8559R7PwwFTPTFgYFAIKTpShACoYcCK3P6/gWEQOjvCIUQVLfQDqa1PeOck1JKaq0dhgImW23H1MIBzSmBAwO1QAiEXhRY2TMgBEIfInQBukhfuKdtnJAAAAAASUVORK5CYII=&quot;) !important; background-position: left top, left top !important; background-size: auto, 32px 16px !important; background-repeat: repeat-y, repeat-y !important; background-origin: padding-box, padding-box !important; padding-left: 40px !important; font-size: 16px;">
    <input type="button" onclick="changeOuterColor()" value="更改">
</div>
<?php stdfoot(); ?>
