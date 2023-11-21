<?php
require "../include/bittorrent.php";
dbconn();
require_once(get_langfile_path());
loggedinorreturn();
parked();
if ($enablebitbucket_main != 'yes')
	permissiondenied();

// 图片要求规范
$maxfilesize = 256 * 1024;
$imgtypes = array (null,'gif','jpg','png');
$scaleh = 200; // set our height size desired
$scalew = 150; // set our width size desired

/*
 * 上传本地图片到第三方图床
 */
function uploadToImageInk($filePath) {
    // 图床接口 URL
    $uploadUrl = 'https://img.ink/api/upload';

    // 待上传的文件路径
    $file = new CURLFile($filePath, mime_content_type($filePath), basename($filePath));

    // POST 数据
    $postData = array(
        'image' => $file,
    );

    // 初始化 cURL 会话
    $ch = curl_init($uploadUrl);

    // 设置 cURL 选项
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // 添加头部信息（带有 token）
    $token = '4efabd9c445ebeade3971cf597c53e46';
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'token: ' . $token,
    ));

    // 执行 cURL 请求
    $responseData = curl_exec($ch);

    // 检查是否有错误发生
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
    }

    // 关闭 cURL 会话
    curl_close($ch);

    // 检查状态码
    if ($responseData['code'] == 200) {
        // 上传成功
        $name = $responseData['data']['name'];
        $url = $responseData['data']['url'];

        // 输出上传成功的信息
        echo 'Upload successful:';
        echo 'Name: ' . $name;
        echo 'URL: ' . $url;
    } else {
        // 上传失败
        echo 'Upload failed. Error message: ' . $responseData['msg'];
    }

}

//检查请求类型
if ($_SERVER["REQUEST_METHOD"] == "POST")
{
    //检查是否接收到文件以及文件大小是否合适。
    //获取文件名，并确保它符合预期。
	$file = $_FILES["file"];
	if (!isset($file) || $file["size"] < 1)
	stderr($lang_bitbucketupload['std_upload_failed'], $lang_bitbucketupload['std_nothing_received']);
	if ($file["size"] > $maxfilesize)
	stderr($lang_bitbucketupload['std_upload_failed'], $lang_bitbucketupload['std_file_too_large']);
	$pp=pathinfo($filename = $file["name"]);
	if($pp['basename'] != $filename)
	stderr($lang_bitbucketupload['std_upload_failed'], $lang_bitbucketupload['std_bad_file_name']);

    //构建目标文件的路径，并检查文件是否已经存在，如果存在则报错。
	$tgtfile = getFullDirectory("$bitbucket/$filename");
	if (file_exists($tgtfile))
	stderr($lang_bitbucketupload['std_upload_failed'], $lang_bitbucketupload['std_file_already_exists'].htmlspecialchars($filename).$lang_bitbucketupload['std_already_exists'],false);

    //获取上传图像的尺寸和类型，并检查文件类型是否符合预期。
	$size = getimagesize($file["tmp_name"]);
	$height = $size[1];
	$width = $size[0];
	$it = $size[2];
	if($imgtypes[$it] == null || $imgtypes[$it] != strtolower($pp['extension']))
	stderr($lang_bitbucketupload['std_error'], $lang_bitbucketupload['std_invalid_image_format'],false);

	// 图像缩放 计算缩放比例，并计算新的图像宽度和高度。 Scale image to appropriate avatar dimensions
	$hscale=$height/$scaleh;
	$wscale=$width/$scalew;
	$scale=($hscale < 1 && $wscale < 1) ? 1 : (( $hscale > $wscale) ? $hscale : $wscale);
	$newwidth=floor($width/$scale);
	$newheight=floor($height/$scale);

    //根据文件类型创建相应的图像资源 $orig
	if ($it==1)
		$orig=@imagecreatefromgif($file["tmp_name"]);
	elseif ($it == 2)
		$orig=@imagecreatefromjpeg($file["tmp_name"]);
	else
		$orig=@imagecreatefrompng($file["tmp_name"]);
    if(!$orig)
	stderr($lang_bitbucketupload['std_image_processing_failed'],$lang_bitbucketupload['std_sorry_the_uploaded']."$imgtypes[$it]".$lang_bitbucketupload['std_failed_processing']);

    // 创建新的缩略图
    // imagecreatetruecolor() 创建新的缩略图 $thumb。
    // imagecopyresized() 将原始图像缩放并复制到新的缩略图上。
    $thumb = imagecreatetruecolor($newwidth, $newheight);
	imagecopyresized($thumb, $orig, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);

    //保存处理后的图像文件到目标位置：
    switch ($it) {
        case 1:
            $ret = imagegif($thumb, $tgtfile);
            break;
        case 2:
            $ret = imagejpeg($thumb, $tgtfile);
            break;
        default:
            $ret = imagepng($thumb, $tgtfile);
    }
//	$ret=($it==1)?imagegif($thumb, $tgtfile): ($it==2)?imagejpeg($thumb, $tgtfile):imagepng($thumb, $tgtfile);

    // 构建文件链接
	$url = str_replace(" ", "%20", htmlspecialchars(get_protocol_prefix()."$BASEURL/bitbucket/$filename"));
	$name = sqlesc($filename);
	$added = sqlesc(date("Y-m-d H:i:s"));
	// 是否为公开图片
    if (!isset($_POST['public']) || $_POST['public'] != 'yes' )
	$public='"0"';
	else
	$public='"1"';

    // 上传至第三方图床
    uploadToImageInk($url);

    //将文件信息插入数据库，并更新用户的头像信息。
	sql_query("INSERT INTO bitbucket (owner, name, added, public) VALUES ({$CURUSER['id']}, $name, $added, $public)") or sqlerr(__FILE__, __LINE__);
	sql_query("UPDATE users SET avatar = ".sqlesc($url)." WHERE id = {$CURUSER['id']}") or sqlerr(__FILE__, __LINE__);

    // 输出成功消息
	stderr($lang_bitbucketupload['std_success'], $lang_bitbucketupload['std_use_following_url']."<br /><b><a href=\"$url\">$url</a></b><p><a href=bitbucket-upload.php>".$lang_bitbucketupload['std_upload_another_file']."</a>.<br /><br /><img src=\"$url\" border=0><br /><br />".$lang_bitbucketupload['std_image']. ($width=$newwidth && $height==$newheight ? $lang_bitbucketupload['std_need_not_rescaling']:$lang_bitbucketupload['std_rescaled_from']."$height x $width".$lang_bitbucketupload['std_to']."$newheight x $newwidth") .$lang_bitbucketupload['std_profile_updated'],false);
}
//生成标准顶栏包括个人信息栏及以上的所有内容
stdhead($lang_bitbucketupload['head_avatar_upload']);
?>
<!--头像上传标题-->
<h1><?php echo $lang_bitbucketupload['text_avatar_upload'] ?></h1>
<!--表单主体-->
<form method="post" action=bitbucket-upload.php enctype="multipart/form-data">
<table border=1 cellspacing=0 cellpadding=5>
<?php

//如果不可写则输出下面的一行
if(!is_writable(ROOT_PATH . "$bitbucket"))
print("<tr><td align=left colspan=2>".$lang_bitbucketupload['text_upload_directory_unwritable']."</tr></td>");
// 提示语, 四行
print("<tr><td align=left colspan=2>".$lang_bitbucketupload['text_disclaimer']."$scaleh".$lang_bitbucketupload['text_disclaimer_two']."$scalew".$lang_bitbucketupload['text_disclaimer_three'].number_format($maxfilesize).$lang_bitbucketupload['text_disclaimer_four']);
?>
<tr>
    <td class=rowhead>
<!--        文件-->
        <?php echo $lang_bitbucketupload['row_file'] ?>
    </td>
    <td class="rowfollow">
<!--        选择文件按钮-->
        <input type="file" name="file" size="60">
    </td>
</tr>
<tr>
    <td colspan=2 align=left class="toolbox">
<!--        共享头像-->
        <input class="checkbox" type=checkbox name=public value=yes><?php echo $lang_bitbucketupload['checkbox_avatar_shared']?>
<!--        上传-->
        <input type="submit" value=<?php echo $lang_bitbucketupload['submit_upload'] ?>>
    </td>
</tr>
</table>
</form>
<?php
stdfoot();
