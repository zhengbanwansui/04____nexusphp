<?php

use Nexus\Database\NexusDB;
use Carbon\Carbon;
use App\Exceptions\NexusException;

require_once('../include/bittorrent.php');
dbconn();
require_once(get_langfile_path());
require(get_langfile_path("",true));
loggedinorreturn();
parked();
global $CURUSER;
// 定义对象
$loanTurnipDao = new \App\Repositories\BonusRepository();
$teamDao = new \App\Repositories\CustomTeamRepository();
$turnipDaily = getTodayTurnip();
$teamMember = getTeamMember();
$teamPic = $teamDao->getTeamPic();
$vsType = $teamDao->getVsTypeList();
$todayBattleCount = $teamDao->getTodayBattleCount($CURUSER['id']);
function getMaxLoan() {
    return 300000;
}

function getWeekDayNumber() {
    return date('N');
//    return "7";
//    return "6";
}

function getLastSunday() {
    $today = date('Y-m-d');
    $previousWeekend = date('Y-m-d', strtotime('last Sunday', strtotime($today)));

    if (date('N', strtotime($today)) == "7") {
        // 如果今天是周末，则使用今天的日期
        $startTime = $today . ' 00:00:00';
    } else {
        // 如果今天不是周末，则使用上一个周末的日期
        $startTime = $previousWeekend . ' 00:00:00';
    }
    return $startTime;
}
function getMaxProfit() {
    $start_date = strtotime('2023-12-18 00:00:00');
    $today_date = strtotime(date('Y-m-d 00:00:00'));
    $days_since_start = floor(($today_date - $start_date) / (60 * 60 * 24));
    $max_loan = 500000 + $days_since_start * 10000;
    if ($days_since_start > 150) {
        $max_loan = 500000 + 150 * 10000 + ($days_since_start-150) * 5000;
    }
    return $max_loan;
}
function getProfit() {
    global $CURUSER;
    $profit = NexusDB::table("custom_turnip_profit")->where("user_id", $CURUSER['id'])->first();
    if($profit === null) {
        return 0;
    }
    return $profit->price;
}
function getTodayTurnip() {
    date_default_timezone_set('Asia/Shanghai');//设置时区为上海
    $am_timestamp = strtotime(date('Y-m-d'));//获取当前时间的0点时间戳
    $pm_timestamp = strtotime(date('Y-m-d') . ' 12:00:00');//获取当前时间的12点时间戳
    if (date('A') == 'AM') {
        $date_str = date('Y-m-d 00:00:00', $am_timestamp);
    } else {
        $date_str = date('Y-m-d 12:00:00', $pm_timestamp);
    }
//    echo $date_str;
    $queryCalendarResult = NexusDB::table("custom_turnip_calendar")->where('date', $date_str)->first();
    return $queryCalendarResult;
}
function getTurnip() {
    global $CURUSER;
    $oldRecord = NexusDB::table("custom_turnip")
        ->where('user_id', $CURUSER['id'])
        ->where('created_at', '>', getLastSunday())
        ->first();
    return $oldRecord;
}
function totalToRepay($userid) {
    $loanInfo = NexusDB::table('custom_loan_repayment')->where('user_id', $userid)->first();
    if ($loanInfo !== null) {
        // 计算贷款总利息
        $seedbonus = $loanInfo->seedbonus; // 贷款的钱数
        $createdAt = strtotime($loanInfo->created_at); // 贷款开始时间的时间戳
        $today = time(); // 今天的时间戳
        $daysPassed = floor(($today - $createdAt) / (60 * 60 * 24)); // 已经过去的天数
        $totalInterest = $seedbonus * pow(1.02, $daysPassed) - $seedbonus; // 总利息
        // 计算用户今天需要还款的总额
        $result = $seedbonus + $totalInterest; // 总共需要还的钱数
        if ($result > $seedbonus * 2) {
            return $seedbonus * 2;
        }
        // 返回还款
        return $result;
    } else {
        return 0.0;
    }
}
function getTeamPiece() {
    global $CURUSER;
    $record = NexusDB::table("custom_team_piece")
        ->where('user_id', $CURUSER['id'])
        ->first();
    return $record;
}
function getTeamMember() {
    global $CURUSER;
    $record = NexusDB::table("custom_team_member")
        ->where('user_id', $CURUSER['id'])
        ->get();
    return $record;
}
function bonusarray($option = 0){
    global $onegbupload_bonus,$fivegbupload_bonus,$tengbupload_bonus,$oneinvite_bonus,$customtitle_bonus,$vipstatus_bonus, $basictax_bonus, $taxpercentage_bonus, $bonusnoadpoint_advertisement, $bonusnoadtime_advertisement;
    global $lang_mybonus;
    global $CURUSER;
    global $teamDao;
    global $turnipDaily;
    global $teamMember;
    global $vsType;
    global $teamPic;
    $oldRecord = getTurnip();
    $profit = getProfit();
    $teamPiece = getTeamPiece();


    $results = [];

    // 小象友善竞技场
    $bonus = array();
    $bonus['points'] = 0;
    $bonus['art'] = 'vs';
    $bonus['menge'] = 0; // 1gb的字节数
    $bonus['name'] = "<b style='color: #ff8000;'>小象友善竞技场【<b style='color: red'>".$vsType[$teamDao->getTodayVsType()]."</b> 战场已开启】</b>"; // text
    $bonus['description'] = "
<br>每人每天拥有三次参战机会，每场战斗最长持续30回合，击溃敌方全体角色获得胜利;
<br>周一和周三是锋芒交错的时刻，1v1的激烈对决等着您；
<br>周二周四上演龙与凤的抗衡，5v5的团战战场精彩纷呈；
<br>周五、周六和周日，世界boss【Sysrous】将会降临，勇士们齐心协力，挑战最强BOSS，获得奖励Sysrous魔力/130000+总伤害/3的象草
<br>点击选择你要派上场的角色，进入竞技场与众多玩家一同展开激烈对抗吧！";
    $results[] = $bonus;

    // 英灵殿角色展示
    $bonus = array();
    $bonus['points'] = 1000;// 加个
    $bonus['art'] = 'team'; // type
    $bonus['menge'] = 0; // 1gb的字节数
    $bonus['name'] = "<b style='color: #903838;'>象岛英灵殿 【诸神归位".count($teamMember)."/".count($teamPic)."】</b>"; // text
    $imgStr = "";
    foreach ($teamMember as $index =>$member) {
        //隐藏表单 - 名称 hidden
        $hiddenInputMemberName = "<input type='hidden' name='memberName' value='".$member->name."' disabled='true'/>";
        $hiddenInputMemberId = "<input type='hidden' name='memberId' value='".$member->id."' disabled='true'/>";
        // 图片
        if (strpos($member->info, "♂")) {$sex = "boy";} else {$sex = "girl";};
        if (strpos($member->info, "限定")) {
            $memberImg = "<img onmousedown='selectMember(this)' class='memberPic SSR ".$sex."' src='".$teamPic[$member->name]."'>";
        } else {
            $memberImg = "<img onmousedown='selectMember(this)' class='memberPic' src='".$teamPic[$member->name]."'>";
        }
        // 选择角色
        $memberSelected = "<input class=\"memberSelected\" type='hidden' name='memberSelected' value=\"". $index ."\" disabled/>";
        $memberSelectedPic = "<img class='memberSelectedPic' style='display: none;' src='https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_6e7d78ee484a7.png'>";
        // 按钮
        $memberBtn="<input class=\"memberBtn\" type=\"submit\" name=\"submit\" value=\"". "投喂" ."\" disabled='true'/>";
        // 数量
        $memberNum="<input class='memberNum' type='number' value='1' max='".($member->lv + 10)."' min='1' name='foodNum' value=\"".$turnipDaily->name ."\" required disabled='true'/>";
        // 文本
        $memberText="<div class=\"memberText\">".$member->info;
        $today_start = date('Y-m-d 00:00:00');
        if ($member->last_feed_at == $today_start) {
            $memberText=$memberText."<br><br>【投喂】吃饱了~";
        } else {
            $memberText=$memberText."<br><br>【投喂".$turnipDaily->name."】 (花费按照蔬菜市场价计算,每天限一次)".
            "<br>&nbsp;&nbsp;&nbsp;数量&nbsp;&nbsp;&nbsp;".$memberNum." 个 x ".$turnipDaily->price."象草 ".$memberBtn;
        }
//        $memberText=$memberText."<br><br>【投喂".$turnipDaily->name."】 (花费按照蔬菜市场价计算,每天限一次)".
//            "<br>&nbsp;&nbsp;&nbsp;数量&nbsp;&nbsp;&nbsp;".$memberNum." 个 x ".$turnipDaily->price."象草 ".$memberBtn;
        $memberText=$memberText.
            "<br><br>【等级】".$member->lv.
            "<br>【经验】".$member->exp.
            "<br>【生命】".$member->hp.
            "<br>【攻击】".$member->atk.
            "<br>【防御】".$member->def.
            $hiddenInputMemberName.
            $hiddenInputMemberId.
            "</div>";
        // 拼接div块
        $imgStr = $imgStr.
            "<div class='member' onmouseover='enableInputs(this)' onmouseout='disableInputs(this)'  >".
                $memberImg.
                "<div class='selectSquare' onmousedown='selectMember(this)' >".
                    $memberSelectedPic.
                    $memberSelected.
                "</div>".
                $memberText.
            "</div>";
    }
    $imgStr = "<div class='allMember'>".$imgStr."</div>";
    $bonus['description'] = "角色碎片数量: ".$teamPiece->info."<br><br>".$imgStr;// text
    $results[] = $bonus;

    $upString = "<b class='rainbow'>".$teamDao->getUpMemberName()."抽取概率UP!</b>";
    // 扭蛋机1
    $bonus = array();
    $bonus['points'] = $teamDao->gachaOncePrice();// 加个
    $bonus['art'] = 'gacha'; // type
    $bonus['times'] = 1; // 抽多少次
    $bonus['menge'] = 0; // 1gb的字节数
    $bonus['name'] = "<b style='color: #646bff;'>小象智能扭蛋机 Plus【单抽出奇迹 ".$upString."】</b> "; // text
    $bonus['description'] = "";// text
    $results[] = $bonus;
    // 扭蛋机10
    $bonus = array();
    $bonus['points'] = $teamDao->gachaOncePrice() * 10;// 加个
    $bonus['art'] = 'gacha'; // type
    $bonus['times'] = 10; // 抽多少次
    $bonus['menge'] = 0; // 1gb的字节数
    $bonus['name'] = "<b style='color: #ff6464;'>小象智能扭蛋机 Pro Max Ultra 至尊豪华Master版【十连保平安</b> " .$upString."<b style='color: #ff6464;'>】保底UPx1</b>"; // text
    $bonus['description'] = "当您想要收集更多可爱的伙伴时，欢迎来到小象智能扭蛋机！
<br>我们提供抽奖服务，让您轻松地获得角色碎片，集齐10枚碎片可以获得对应角色。
<br>抽奖一次的价格每天增加10象草, 每日up角色轮替, 十连抽保底获得当期up角色碎片。
<br>在这里，您可以发现新角色、展示您的收藏，并享受无尽的乐趣。快来体验吧！";
    $results[] = $bonus;

    // 查一下当前持仓
    $currentNumber = 0;
    $currentPrice = 0;
    if ($oldRecord !== null) {
        $currentNumber = $oldRecord->number;
        $currentPrice = $oldRecord->price;
    }
    // 进货 象岛农庄
    $bonus = array();
    $bonus['art'] = 'buyTurnip'; // type
    $bonus['menge'] = 0; // 1gb的字节数
    if (getWeekDayNumber() == "7") {
        $bonus['points'] = $turnipDaily->price;
        $bonus['name'] = "<b style='color: #288002;'>象岛农庄 【" . $turnipDaily->name . " - 作物成熟 - <b class='rainbow'>开售中</b>】</b>";
        $bonus['description'] = $turnipDaily->name."的价格是".$turnipDaily->price."，保质期至下周六晚上，要马上进货吗？";
    } else {
        $bonus['points'] = 0;
        $bonus['name'] = "<b style='color: #288002;'>象岛农庄 【科学种植中 - 等待成熟】</b>";
        $bonus['description'] = "每周日新的流行农作物成熟，小象可以来这里批发进货";
    }
    $bonus['maxNum'] = intval($CURUSER['seedbonus'] / $turnipDaily->price);
    $bonus['finishTarget'] = 0;
    if (getMaxProfit() <= $profit) {
        $bonus['finishTarget'] = 1;
    }
    $results[] = $bonus;

    // 出售
    $bonus = array();
    $bonus['points'] = $turnipDaily->price;
    $bonus['art'] = 'saleTurnip'; // type
    $bonus['menge'] = 0;
    if (getWeekDayNumber() == "7") {
        $bonus['name'] = "<b style='color: #288002;'>小象新鲜蔬菜店</b> 【休息日】 ".$turnipDaily->name."库存：".$currentNumber." 成本：".$currentPrice;
        $bonus['description'] = "岛民都在家看硬盘里的影视资源，没人来买东西了";
        $bonus['maxNum'] = 0;
    } else {
        $bonus['name'] = "<b style='color: #288002;'>小象新鲜蔬菜店</b> 【" .$turnipDaily->name."<b class='rainbow'> 市场单价：".$turnipDaily->price."</b> "."库存：".$currentNumber." 成本：".$currentPrice."】";
        $bonus['description'] = "价格每12小时波动一次 ".$turnipDaily->name."~ ".$turnipDaily->name."~ "."能涨价就太好了~~  开店累计盈利 ".$profit." 盈利目标 ".getMaxProfit()."(净利润, 每日自动增加)";
        // 售价比进货价高, 考虑两个上限
        if ($turnipDaily->price > $currentPrice) {
            $userCanProfit = bcsub(getMaxProfit(), $profit);
            $userProfitOneProduct = bcsub($turnipDaily->price, $currentPrice);
            $profitNum = bcdiv($userCanProfit, $userProfitOneProduct, 0) + 1;
            $bonus['maxNum'] = min($currentNumber, $profitNum);
        }
        // 售价比进货价低肯定是亏损, 不考虑盈利上限问题
        else {
            $bonus['maxNum'] = $currentNumber;
        }
    }
    $results[] = $bonus;

    //借款
    $bonus = array();
    $bonus['points'] = 0.0;// 加个
    $bonus['art'] = 'loan'; // type
    $bonus['menge'] = 0; // 1gb的字节数
    $bonus['name'] = "贷款"; // text
    $bonus['description'] = "福利象草贷, 手续费0元, 日息低至2%, 你只能同时申请一笔贷款";// text
    $results[] = $bonus;

    //还款
    $bonus = array();
    $bonus['points'] = 100000;// 加个
    $bonus['art'] = 'repayment'; // type
    $bonus['menge'] = 0; // 1gb的字节数
    $bonus['name'] = "偿还贷款"; // text
    $bonus['description'] = "是时候付出代价了, 偿还你的上一笔贷款, 长期不还有概率被管理员强制执行或资产抵债";// text
    $results[] = $bonus;

    return $results;

}

$allBonus = bonusarray();
// 防止连发设置10s缓冲
$lockSeconds = 10;
// 系统限制 $lockSeconds 秒内只能点击交换按钮一次！
$lockText = sprintf($lang_mybonus['lock_text'], $lockSeconds);
// 如果不能点, 则输出系统报错对不起信息 魔力值系统当前处于关闭中 不过你的魔力值仍在计算中
if ($bonus_tweak == "disable" || $bonus_tweak == "disablesave")
    stderr($lang_mybonus['std_sorry'],$lang_mybonus['std_karma_system_disabled'].($bonus_tweak == "disablesave" ? "<b>".$lang_mybonus['std_points_active']."</b>" : ""),false);

// 获取通过GET请求传递的参数，并对参数进行处理和编码，同时
$action = htmlspecialchars($_GET['action'] ?? '');
$do = htmlspecialchars($_GET['do'] ?? '');
// 清除$msg变量的值
unset($msg);

// 如果do有参数值
// 根据do的值搞个$msg出来, 比如$do="upload" 则$msg="祝贺你，你成功增加了<b>上传值</b>！"
if (isset($do)) {
    if ($do == "upload")
        $msg = $lang_mybonus['text_success_upload'];
    elseif ($do == "download")
        $msg = $lang_mybonus['text_success_download'];
    elseif ($do == "invite")
        $msg = $lang_mybonus['text_success_invites'];
    elseif ($do == "tmp_invite")
        $msg = $lang_mybonus['text_success_tmp_invites'];
    elseif ($do == "vip")
        $msg =  $lang_mybonus['text_success_vip']."<b>".get_user_class_name(UC_VIP,false,false,true)."</b>".$lang_mybonus['text_success_vip_two'];
    elseif ($do == "vipfalse")
        $msg =  $lang_mybonus['text_no_permission'];
    elseif ($do == "title")
        $msg = $lang_mybonus['text_success_custom_title'];
    elseif ($do == "transfer")
        $msg =  $lang_mybonus['text_success_gift'];
    elseif ($do == "noad")
        $msg =  $lang_mybonus['text_success_no_ad'];
    elseif ($do == "charity")
        $msg =  $lang_mybonus['text_success_charity'];
    elseif ($do == "cancel_hr")
        $msg =  $lang_mybonus['text_success_cancel_hr'];
    elseif ($do == "buy_medal")
        $msg =  $lang_mybonus['text_success_buy_medal'];
    elseif ($do == "attendance_card")
        $msg =  $lang_mybonus['text_success_buy_attendance_card'];
    elseif ($do == "rainbow_id")
        $msg =  $lang_mybonus['text_success_buy_rainbow_id'];
    elseif ($do == "change_username_card")
        $msg =  $lang_mybonus['text_success_buy_change_username_card'];
    elseif ($do == 'duplicated')
        $msg = $lockText;
    elseif ($do == 'loan_success')
        $msg = "贷款成功";
    elseif ($do == 'loan_failed')
        $msg = "贷款失败";
    elseif ($do == 'repayment_success')
        $msg = "还款成功";
    elseif ($do == 'repayment_failed')
        $msg = "还款失败";
    elseif ($do == 'buy_turnip_success')
        $msg = "进货成功";
    elseif ($do == 'buy_turnip_failed')
        $msg = "进货失败";
    elseif ($do == 'sale_turnip_success')
        $msg = "出售成功";
    elseif ($do == 'sale_turnip_failed')
        $msg = "出售失败";
    elseif (strpos($do, "结果") !== false) {
        $msg = $do;
        if (strpos($do, "抽卡结果") !== false) {
            $msg = "";
            // 获取物品字符串
            $itemsStr = substr($do, strpos($do, "获得物品=") + strlen("获得物品="));
            // 分割字符串
            $nameArray = explode(",", $itemsStr);
            // 处理空格和"x1"
            foreach ($nameArray as $name) {
                $name = trim(str_replace("碎片x1", "", $name));
                $url = $teamDao->getTeamPic()[$name];
                $msg = $msg.
                    "<div class='piecePicBackground'><img class='piecePic' src='".$url."' /></div>";
            }
            $msg = $do."<br>".$msg;
        }
        // $msg中的@@@替换为<br>
        $msg = str_replace('@@@', '<br>', $msg);
    }
    else
        $msg = '';
}
//########################################################################################################
//#### 页面启动 ###########################################################################################
//########################################################################################################
// 标准头部
stdhead($CURUSER['username'] . $lang_mybonus['head_karma_page']);
// 魔力值保留一位小数，并添加千位分隔符
$bonus = number_format($CURUSER['seedbonus'], 1);

// 如果没有动作
if (!$action) {
    // 开始构建兑换奖励的表格
    print("<table id='customMybonus' align=\"center\" width=\"97%\" border=\"1\" cellspacing=\"0\" cellpadding=\"3\">\n");
    // NexusPHP魔力值系统
    print("<tr><td class=\"colhead\" colspan=\"4\" align=\"center\"><font style='color: white; font-size: 24px'>小象快乐岛</font></td></tr>\n");
    // 如果有信息, 则输出信息
    if ($msg) {
        print("<tr><td align=\"center\" colspan=\"4\">");
        if (strpos("xxx".$msg, "战斗结果")) {
            // 从$msg中根据id获取完整的$msg
            $battleDO = NexusDB::table("custom_team_battle")->where("id", explode("=", $msg)[1])->first();
            $msg = $battleDO->info;
//            throw new NexusException($msg,666);
            print("<input id='battleMsgInput' type='hidden' value='".$msg."'>");
            global $teamPic;
            // 取出双方的角色摆上去index=2 我方 index=4敌方
            $msgArray = explode("@@@", $msg);
            $weStr = $msgArray[2];
            $enemyStr = $msgArray[4];
            preg_match_all('/\[(.*?)\]/', $weStr, $matches);
            $we = $matches[1];
            preg_match_all('/\[(.*?)\]/', $enemyStr, $matches);
            $enemy = $matches[1];
            print("<div id='battleResultStringLastShow'>");
                $fightText = str_replace("@@@", "<br>", $msg);
                print("<div class='striking'>". strstr($fightText, "开始战斗", true) ."</div>");
                if ($battleDO->win == 0) {
                    $winText = "战败";
                } else if ($battleDO->win == 1) {
                    $winText = "胜利";
                }else if ($battleDO->win == 2) {
                    $winText = "平局";
                }
                $winText .= " - 获得奖励：".$battleDO->prize."象草";
                print("<div class='striking'>". $winText ."</div>");
            print("</div>");
            print("<div class='battlefield' onload='battle()'><div class='bf_left'>");
            foreach ($we as $one) {
                print("<img id='"."we_".$one."' class='bf_member bf_member_left' src='".$teamPic[$one]."'>");
            }
            print("</div><div class='bf_center'>");

            print("</div><div class='bf_right'>");
            foreach ($enemy as $one) {
                print("<img id='"."enemy_".$one."' class='bf_member bf_member_right' src='".$teamPic[$one]."'>");
            }
            print("</div></div>");
        } else {
            print("<div class='striking'>".$msg."</div>");
        }
        print("</td></tr>");
    }
    ?>
    <!--用你的魔力值（当前109.0）换东东！-->
    <tr><td class="text" align="center" colspan="4"><?php echo $lang_mybonus['text_exchange_your_karma']?><?php echo $bonus?><?php echo $lang_mybonus['text_for_goodies'] ?>
            <!--"如果按钮不可点，则你的魔力值不足以交换该项。$lockText是上面构建的原因-->
            <br /><b><?php echo $lang_mybonus['text_no_buttons_note'] ?></b><br /><small style="color: orangered">(<?php echo $lockText ?>)</small></td></tr>
    <?php

    // 列名栏包括:  项目	  简介  	价格	交换
    print("<tr><td class=\"colhead\" align=\"center\">".$lang_mybonus['col_option']."</td>".// 编号
        "<td class=\"colhead\" align=\"left\">".$lang_mybonus['col_description']."</td>". // 间接
        "<td class=\"colhead\" align=\"center\">".$lang_mybonus['col_points']."</td>". // 价格
        "<td class=\"colhead\" align=\"center\">".$lang_mybonus['col_trade']."</td>".
        "</tr>");

    // 自定的一行
//    print("<tr>");
//    print("<form action=\"?action=exchange\" method=\"post\">");
//        print("<td class=\"rowhead_center\"><input type=\"hidden\" name=\"option\" value=\"0\" /><b>666</b></td>");
//        print("<td class=\"rowhead_center\"><input type=\"hidden\" name=\"option\" value=\"0\" /><b>内容</b></td>");
//        print("<td class=\"rowhead_center\"><input type=\"hidden\" name=\"option\" value=\"0\" /><b>64800</b></td>");
//    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "召唤小象" ."\" /></td>");
//    print("</form>");
//    print("</tr>");

    // 遍历显示每一项奖励
    for ($i=0; $i < count($allBonus); $i++)
    {
        //$bonusarray是一个奖励对象
        $bonusarray = $allBonus[$i];
        if (
            ($bonusarray['art'] == 'gift_1' && $bonusgift_bonus == 'no')
            || ($bonusarray['art'] == 'noad' && ($enablead_advertisement == 'no' || $bonusnoad_advertisement == 'no'))
            || ($bonusarray['art'] == 'cancel_hr' && !\App\Models\HitAndRun::getIsEnabled())
        ) {
            // 没广告的网站, 不需要显示去掉广告这一项兑换
            // 没hr的网站, 不需要显示去掉hr这一项兑换
            // 处理下一个奖励
            continue;
        }
        // 开始构建一行了

        print("<tr>");

        print("<form action=\"?action=exchange\" method=\"post\">");

        // 编号
        print("<td class=\"rowhead_center\"><input type=\"hidden\" name=\"option\" value=\"".$i."\" /><b>".($i + 1)."</b></td>");

        // 名称和价格
        if ($bonusarray['art'] == 'title'){ //for Custom Title!
            $otheroption_title = "<input type=\"text\" name=\"title\" style=\"width: 200px\" maxlength=\"30\" />";
            print("<td class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."<br /><br />".$lang_mybonus['text_enter_titile'].$otheroption_title.$lang_mybonus['text_click_exchange']."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
        }
        elseif ($bonusarray['art'] == 'gift_1'){  //for Give A Karma Gift 赠送的魔力值(芙蓉王)1万上限
            $otheroption = "<table width=\"100%\"><tr><td class=\"embedded\"><b>".$lang_mybonus['text_username']."</b><input type=\"text\" name=\"username\" style=\"width: 200px\" maxlength=\"24\" /></td><td class=\"embedded\"><b>".$lang_mybonus['text_to_be_given']."</b><input type=\"number\" name=\"bonusgift\" id=\"giftcustom\" style='width: 80px' min='10000' />".$lang_mybonus['text_karma_points']."</td></tr><tr><td class=\"embedded\" colspan=\"2\"><b>".$lang_mybonus['text_message']."</b><input type=\"text\" name=\"message\" style=\"width: 400px\" maxlength=\"100\" /></td></tr></table>";
            print("<td class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."<br /><br />".$lang_mybonus['text_enter_receiver_name']."<br />$otheroption</td><td class=\"rowfollow nowrap\" align='center'>".$lang_mybonus['text_min']."10000</td>");
        }
        elseif ($bonusarray['art'] == 'gift_2'){  //charity giving 赠送的慈善魔力粉
            $otheroption = "<table width=\"100%\"><tr><td class=\"embedded\">".$lang_mybonus['text_ratio_below']."<select name=\"ratiocharity\"> <option value=\"0.1\"> 0.1</option><option value=\"0.2\"> 0.2</option><option value=\"0.3\" selected=\"selected\"> 0.3</option> <option value=\"0.4\"> 0.4</option> <option value=\"0.5\"> 0.5</option> <option value=\"0.6\"> 0.6</option><option value=\"0.7\"> 0.7</option><option value=\"0.8\"> 0.8</option></select>".$lang_mybonus['text_and_downloaded_above']." 10 GB</td><td class=\"embedded\"><b>".$lang_mybonus['text_to_be_given']."</b><select name=\"bonuscharity\" id=\"charityselect\" > <option value=\"1000\"> 1,000</option><option value=\"2000\"> 2,000</option><option value=\"3000\" selected=\"selected\"> 3000</option> <option value=\"5000\"> 5,000</option> <option value=\"8000\"> 8,000</option> <option value=\"10000\"> 10,000</option><option value=\"20000\"> 20,000</option><option value=\"50000\"> 50,000</option></select>".$lang_mybonus['text_karma_points']."</td></tr></table>";
            print("<td class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."<br /><br />".$lang_mybonus['text_select_receiver_ratio']."<br />$otheroption</td><td class=\"rowfollow nowrap\" align='center'>".$lang_mybonus['text_min']."1,000<br />".$lang_mybonus['text_max']."50,000</td>");
        }
        elseif ($bonusarray['art'] == 'loan') {
            $otheroption_title = "<input min=\"10000\" max=\"".getMaxLoan()."\" type=\"number\" name=\"loanBonus\" style=\"width: 200px\" required='true' />";
            print("<td id=\"loan\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."<br /><br />"."输入你想要的<b>贷款额度</b> ".$otheroption_title." 点击贷款 !"."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
        }
        elseif ($bonusarray['art'] == 'repayment') {
            $bonusarray['points'] = totalToRepay($CURUSER['id']);
            print("<td id=\"repayment\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
        }
        elseif ($bonusarray['art'] == 'buyTurnip') {
            if (getWeekDayNumber() == "7") {
                $disable = "";
                if ($bonusarray['maxNum'] == 0) {
                    $disable = "disabled";
                }
                $tempInput = "<input min='1' max=\"".$bonusarray['maxNum']."\" type=\"number\" name=\"buyTurnipNum\" style=\"width: 200px\" required='true' ".$disable."/>";
                print("<td id=\"buyTurnipSunday\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']
                    ."<br /><br />"."输入<b>进货数量</b> ".$tempInput." 点击进货 !"
                    ."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
            } else {
                print("<td id=\"buyTurnip\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'> </td>");
            }
        }
        elseif ($bonusarray['art'] == 'saleTurnip') {
            if (getWeekDayNumber() !== "7") {
                $disable = "";
                if ($bonusarray['maxNum'] == 0) {
                    $disable = "disabled";
                }
                $tempInput = "<input min='1' max=\"".$bonusarray['maxNum']."\" type=\"number\" name=\"saleTurnipNum\" style=\"width: 200px\" required='true' ".$disable."/>";
                print("<td id=\"saleTurnip\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']
                    ."<br /><br />"."输入<b>出售数量</b> ".$tempInput." 点击出售 ! (超过盈利目标后多余的库存会自动原价卖出)"
                    ."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
            } else {
                print("<td id=\"saleTurnip\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'> </td>");
            }
        }
        elseif ($bonusarray['art'] == 'gacha') {
            // 10抽
            if ($bonusarray['times'] == 10) {
                print("<td id=\"gacha10\"  class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
            }
            // 单抽
            elseif ($bonusarray['times'] == 1) {
                print("<td id=\"gacha1\"  class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
            }
        }
        elseif ($bonusarray['art'] == 'team') {
            print("<td  colspan=\"3\" class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td>");//<td class=\"rowfollow\" align='center'>"."</td>"
        }
        elseif ($bonusarray['art'] == 'vs') {
            print("<td id='vs' class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
        }
        else {  //for VIP or Upload
            // 其他项的输出简介的主副标题, 价格
            print("<td class=\"rowfollow\" align='left'><h1>".$bonusarray['name']."</h1>".$bonusarray['description']."</td><td class=\"rowfollow\" align='center'>".number_format($bonusarray['points'])."</td>");
        }

        // 交换的按钮
        // [卖出类型按钮] [其他按钮]
        if ($bonusarray['art'] == 'saleTurnip') {
            if (getWeekDayNumber() !== "7") {
                $disable = "";
                if ($bonusarray['maxNum'] == 0) {
                    $disable = "disabled";
                }
                print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "出售" ."\" ".$disable."/></td>");
            } else {
                print("<td class=\"rowfollow\" align=\"center\"> </td>");
            }
        }
        elseif ($bonusarray['art'] == 'team') {
            // 按钮行被标题那列占用掉了
        }
        elseif ($bonusarray['art'] == 'vs') {
            // 竞技场的战斗按钮
            // 竞技场的战斗按钮
            // 竞技场的战斗按钮
            global $teamDao;
            global $vsType;
            print("<td class=\"rowfollow\" align=\"center\">");
            print("<b>今日剩余战斗次数: ".(3 - $todayBattleCount)."</b><br><br>");
                print("<input id='vs_member_id' type='hidden' name='vs_member_name'>");
            $battleName = $teamDao->getVsTypeList()[$teamDao->getTodayVsType()];
            if ($todayBattleCount == 3) {$disableBattle = "disabled";} else {$disableBattle = "";}
            print("<input id='vs_submit' type=\"submit\" name=\"submit\" value='".$battleName."' ".$disableBattle."/></td>");

        }
        // [消费类型按钮]
        else if($CURUSER['seedbonus'] >= $bonusarray['points'])
        {
            $permission = 'sendinvite';
            if ($bonusarray['art'] == 'gift_1'){
                print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_karma_gift']."\" /></td>");
            }
            elseif ($bonusarray['art'] == 'noad'){
                if ($enablenoad_advertisement == 'yes' && get_user_class() >= $noad_advertisement)
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_class_above_no_ad']."\" disabled=\"disabled\" /></td>");
                elseif (strtotime($CURUSER['noaduntil']) >= TIMENOW)
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_already_disabled']."\" disabled=\"disabled\" /></td>");
                elseif (get_user_class() < $bonusnoad_advertisement)
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".get_user_class_name($bonusnoad_advertisement,false,false,true).$lang_mybonus['text_plus_only']."\" disabled=\"disabled\" /></td>");
                else
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            }
            elseif ($bonusarray['art'] == 'gift_2'){
                print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_charity_giving']."\" /></td>");
            }
            elseif($bonusarray['art'] == 'invite')
            {
                if (\App\Models\Setting::get('main.invitesystem') != 'yes')
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".nexus_trans('invite.send_deny_reasons.invite_system_closed')."\" disabled=\"disabled\" /></td>");
                elseif(!user_can($permission, false, 0)){
                    $requireClass = get_setting("authority.$permission");
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".nexus_trans('invite.send_deny_reasons.no_permission', ['class' => \App\Models\User::getClassText($requireClass)])."\" disabled=\"disabled\" /></td>");}
                else
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            }
            elseif($bonusarray['art'] == 'tmp_invite')
            {
                if (\App\Models\Setting::get('main.invitesystem') != 'yes')
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".nexus_trans('invite.send_deny_reasons.invite_system_closed')."\" disabled=\"disabled\" /></td>");
                elseif(!user_can($permission, false, 0)){
                    $requireClass = get_setting("authority.$permission");
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".nexus_trans('invite.send_deny_reasons.no_permission', ['class' => \App\Models\User::getClassText($requireClass)])."\" disabled=\"disabled\" /></td>");}
                else
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            }
            elseif ($bonusarray['art'] == 'class')
            {
                if (get_user_class() >= UC_VIP)
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['std_class_above_vip']."\" disabled=\"disabled\" /></td>");
                else
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            }
            elseif ($bonusarray['art'] == 'title')
                print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            elseif ($bonusarray['art'] == 'traffic')
            {
                if ($CURUSER['downloaded'] > 0){
                    if ($CURUSER['uploaded'] > $dlamountlimit_bonus * 1073741824)//Uploaded amount reach limit
                        $ratio = $CURUSER['uploaded']/$CURUSER['downloaded'];
                    else $ratio = 0;
                }
                else $ratio = $ratiolimit_bonus + 1; //Ratio always above limit
                // 根据上传量和下载量的多少决定是否能点交换, 显示分享率过高
                if ($ratiolimit_bonus > 0 && $ratio > $ratiolimit_bonus){
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['text_ratio_too_high']."\" disabled=\"disabled\" /></td>");
                } else print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            }
            elseif ($bonusarray['art'] == 'change_username_card') {
                if (\App\Models\UserMeta::query()->where('uid', $CURUSER['id'])->where('meta_key', \App\Models\UserMeta::META_KEY_CHANGE_USERNAME)->exists()) {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['text_change_username_card_already_has']."\" disabled=\"disabled\"/></td>");
                } else {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
                }
            }
            elseif ($bonusarray['art'] == 'rainbow_id') {
                if (\App\Models\UserMeta::query()->where('uid', $CURUSER['id'])->where('meta_key', \App\Models\UserMeta::META_KEY_PERSONALIZED_USERNAME)->whereNull('deadline')->exists()) {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['text_rainbow_id_already_valid_forever']."\" disabled=\"disabled\"/></td>");
                } else {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
                }
            }
            elseif ($bonusarray['art'] == 'loan') {
                $record = NexusDB::table('custom_loan_repayment')->where('user_id', $CURUSER['id'])->get();
                if ($record->isEmpty()) {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "贷款" ."\" /></td>");
                } else {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "已放贷" ."\" disabled=\"disabled\" /></td>");
                }
            }
            elseif ($bonusarray['art'] == 'repayment') {
                $record = NexusDB::table('custom_loan_repayment')->where('user_id', $CURUSER['id'])->get();
                if ($record->isEmpty()) {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "无债一身轻" ."\" disabled=\"disabled\" /></td>");
                } else {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "速度还钱" ."\" /></td>");
                }
            }
            elseif ($bonusarray['art'] == 'buyTurnip') {
                if ($bonusarray['finishTarget'] == 1) {
                    print("<td class=\"rowfollow\" align=\"center\">盈利目标已达成</td>");
                }
                elseif (getWeekDayNumber() == "7") {
                    print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"". "进货" ."\" /></td>");
                } else {
                    print("<td class=\"rowfollow\" align=\"center\"> </td>");
                }

            }
            else {
                print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['submit_exchange']."\" /></td>");
            }
        }
        else {
            // 消费类按钮的魔力值不够
            print("<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"".$lang_mybonus['text_more_points_needed']."\" disabled=\"disabled\" /></td>");
        }

        print("</form>");
        print("</tr>");

    }

    print("</table><br />");
    ?>
    <?php
}

// 如果动作为交换奖励
if ($action == "exchange") {
    global $turnipDaily;
    // 作弊处理
    if (isset($_POST["userid"]) || isset($_POST["points"]) || isset($_POST["bonus"]) || isset($_POST["art"]) || !isset($_POST['option']) || !isset($allBonus[$_POST['option']])){
        write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is trying to cheat at bonus system",'mod');
        die($lang_mybonus['text_cheat_alert']);
    }

    $option = intval($_POST["option"] ?? 0);
    $bonusarray = $allBonus[$option];
    $points = $bonusarray['points'];
    $userid = $CURUSER['id'];
    $art = $bonusarray['art'];
    if ($art == 'repayment') {
        $points = totalToRepay($CURUSER['id']);
    }
    $bonuscomment = $CURUSER['bonuscomment'];
    $seedbonus=$CURUSER['seedbonus']-$points;

    $bonusRep = new \App\Repositories\BonusRepository();
    global $loanTurnipDao;
    global $teamDao;
    global $teamMember;
    // [出售型] [其他]
    if ($art == 'saleTurnip') {
        $lockName = "user:$userid:exchange:bonus";
        $lock = new \Nexus\Database\NexusLock($lockName, $lockSeconds);
        if (!$lock->get()) {
            do_log("[LOCKED], $lockName, $lockText");
            nexus_redirect('customgame.php?do=duplicated');
        }
        $saleTurnipNum = $_POST["saleTurnipNum"];
        $loanTurnipDao->customSaleTurnip($CURUSER['id'], $points, $saleTurnipNum, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD,   $points. " Points for sale turnip.",   []);
        nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=sale_turnip_success");
    }
    elseif ($art == 'team') {
        $lockName = "user:$userid:exchange:bonus";
        $lock = new \Nexus\Database\NexusLock($lockName, $lockSeconds);
        if (!$lock->get()) {
            do_log("[LOCKED], $lockName, $lockText");
            nexus_redirect('customgame.php?do=duplicated');
        }
        $foodNum = $_POST["foodNum"];
        $memberName = $_POST['memberName'];
        $memberId = $_POST['memberId'];
        $eatResult = $teamDao->eatFoodAddExp($CURUSER['id'], $memberId, $memberName, $foodNum, $turnipDaily->price);
//        echo "日志". $CURUSER['id']." ". $memberId." ". $memberName." ". $foodNum." ". $turnipDaily->price;
        nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=".$eatResult);
    }
    elseif ($art == 'vs') {
        $lockName = "user:$userid:exchange:bonus";
        $lock = new \Nexus\Database\NexusLock($lockName, $lockSeconds);
        if (!$lock->get()) {
            do_log("[LOCKED], $lockName, $lockText");
            nexus_redirect('customgame.php?do=duplicated');
        }
        $memberIndexStr = $_POST["vs_member_name"];
        $vsResult = $teamDao->vs($CURUSER['id'], $teamMember, $memberIndexStr);
        nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=".$vsResult);
    }
    // [消费型]
    elseif($CURUSER['seedbonus'] >= $points) {
        $lockName = "user:$userid:exchange:bonus";
        $lock = new \Nexus\Database\NexusLock($lockName, $lockSeconds);
        if (!$lock->get()) {
            do_log("[LOCKED], $lockName, $lockText");
            nexus_redirect('customgame.php?do=duplicated');
        }
        //=== trade for upload
        if($art == "traffic") {
            if ($CURUSER['uploaded'] > $dlamountlimit_bonus * 1073741824) {
                //uploaded amount reach limit
                if ($CURUSER['downloaded'] > 0) {
                    $ratio = $CURUSER['uploaded']/$CURUSER['downloaded'];
                } else {
                    $ratio = PHP_INT_MAX;
                }
            } else {
                $ratio = 0;
            }
            if ($ratiolimit_bonus > 0 && $ratio > $ratiolimit_bonus)
                die($lang_mybonus['text_cheat_alert']);
            else {
                $upload = $CURUSER['uploaded'];
                $up = $upload + $bonusarray['menge'];
//			$bonuscomment = date("Y-m-d") . " - " .$points. " Points for upload bonus.\n " .$bonuscomment;
//			sql_query("UPDATE users SET uploaded = ".sqlesc($up).", seedbonus = seedbonus - $points, bonuscomment = ".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
                $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD, $points. " Points for uploaded.", ['uploaded' => $up]);
                nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=upload");
            }
        }
        if($art == "traffic_downloaded") {
            $downloaded = $CURUSER['downloaded'];
            $down = $downloaded + $bonusarray['menge'];
            $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_DOWNLOAD, $points. " Points for downloaded.", ['downloaded' => $down]);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=download");
        }
        //=== trade for one month VIP status ***note "SET class = '10'" change "10" to whatever your VIP class number is
        elseif($art == "class") {
            if (get_user_class() >= UC_VIP) {
                stdmsg($lang_mybonus['std_no_permission'],$lang_mybonus['std_class_above_vip'], 0);
                stdfoot();
                die;
            }
            $vip_until = date("Y-m-d H:i:s",(strtotime(date("Y-m-d H:i:s")) + 28*86400));
//			$bonuscomment = date("Y-m-d") . " - " .$points. " Points for 1 month VIP Status.\n " .htmlspecialchars($bonuscomment);
//			sql_query("UPDATE users SET class = '".UC_VIP."', vip_added = 'yes', vip_until = ".sqlesc($vip_until).", seedbonus = seedbonus - $points, bonuscomment=".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
            $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_BUY_VIP, $points. " Points for 1 month VIP Status.", ['class' => UC_VIP, 'vip_added' => 'yes', 'vip_until' => $vip_until]);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=vip");
        }
        //=== trade for invites
        elseif($art == "invite") {
            if(!user_can('buyinvite'))
                die(get_user_class_name($buyinvite_class,false,false,true).$lang_mybonus['text_plus_only']);
            $invites = $CURUSER['invites'];
            $inv = $invites+$bonusarray['menge'];
//			$bonuscomment = date("Y-m-d") . " - " .$points. " Points for invites.\n " .htmlspecialchars($bonuscomment);
//			sql_query("UPDATE users SET invites = ".sqlesc($inv).", seedbonus = seedbonus - $points, bonuscomment=".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
            $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_INVITE, $points. " Points for invites.", ['invites' => $inv, ]);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=invite");
        }
        //=== temporary invite
        elseif($art == "tmp_invite") {
            if(!user_can('buyinvite'))
                die(get_user_class_name($buyinvite_class,false,false,true).$lang_mybonus['text_plus_only']);
//            $invites = $CURUSER['invites'];
//            $inv = $invites+$bonusarray['menge'];
//			$bonuscomment = date("Y-m-d") . " - " .$points. " Points for invites.\n " .htmlspecialchars($bonuscomment);
//			sql_query("UPDATE users SET invites = ".sqlesc($inv).", seedbonus = seedbonus - $points, bonuscomment=".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
            $bonusRep->consumeToBuyTemporaryInvite($CURUSER['id']);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=tmp_invite");
        }
        //=== trade for special title
        /**** the $words array are words that you DO NOT want the user to have... use to filter "bad words" & user class...
        the user class is just for show, but what the hell tongue.gif Add more or edit to your liking.
         *note if they try to use a restricted word, they will recieve the special title "I just wasted my karma" *****/
        elseif($art == "title") {
            //===custom title
            $title = $_POST["title"];
            $words = array("fuck", "shit", "pussy", "cunt", "nigger", "Staff Leader","SysOp", "Administrator","Moderator","Uploader","Retiree","VIP","Nexus Master","Ultimate User","Extreme User","Veteran User","Insane User","Crazy User","Elite User","Power User","User","Peasant","Champion");
            $title = str_replace($words, $lang_mybonus['text_wasted_karma'], $title);
//			$bonuscomment = date("Y-m-d") . " - " .$points. " Points for custom title. Old title is ".htmlspecialchars(trim($CURUSER["title"]))." and new title is $title\n " .htmlspecialchars($bonuscomment);
//			sql_query("UPDATE users SET title = ".sqlesc($title).", seedbonus = seedbonus - $points, bonuscomment = ".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
            $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_CUSTOM_TITLE, $points. " Points for custom title. Old title is ".htmlspecialchars(trim($CURUSER["title"]))." and new title is $title.", ['title' => $title, ]);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=title");
        }
        elseif($art == "noad" && $enablead_advertisement == 'yes' && $enablebonusnoad_advertisement == 'yes') {
            if (($enablenoad_advertisement == 'yes' && get_user_class() >= $noad_advertisement) || strtotime($CURUSER['noaduntil']) >= TIMENOW || get_user_class() < $bonusnoad_advertisement)
                die($lang_mybonus['text_cheat_alert']);
            else{
                $noaduntil = date("Y-m-d H:i:s",(TIMENOW + $bonusarray['menge']));
//				$bonuscomment = date("Y-m-d") . " - " .$points. " Points for ".$bonusnoadtime_advertisement." days without ads.\n " .htmlspecialchars($bonuscomment);
//				sql_query("UPDATE users SET noad='yes', noaduntil='".$noaduntil."', seedbonus = seedbonus - $points, bonuscomment = ".sqlesc($bonuscomment)." WHERE id=".sqlesc($userid));
                $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_NO_AD, $points. " Points for ".$bonusnoadtime_advertisement." days without ads.", ['noad' => 'yes', 'noaduntil' => $noaduntil]);
                nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=noad");
            }
        }
        elseif($art == 'gift_2') // charity giving
        {
            $points = intval($_POST["bonuscharity"] ?? 0);
            if ($points < 1000 || $points > 50000){
                stdmsg($lang_mybonus['text_error'], $lang_mybonus['bonus_amount_not_allowed_two'], 0);
                stdfoot();
                die();
            }
            $ratiocharity = $_POST["ratiocharity"];
            if ($ratiocharity < 0.1 || $ratiocharity > 0.8){
                stdmsg($lang_mybonus['text_error'], $lang_mybonus['bonus_ratio_not_allowed']);
                stdfoot();
                die();
            }
            if($CURUSER['seedbonus'] >= $points) {
                $points2= number_format($points,1);
//				$bonuscomment = date("Y-m-d") . " - " .$points2. " Points as charity to users with ratio below ".htmlspecialchars(trim($ratiocharity)).".\n " .htmlspecialchars($bonuscomment);
                $charityReceiverCount = get_row_count("users", "WHERE enabled='yes' AND 10737418240 < downloaded AND $ratiocharity > uploaded/downloaded");
                if ($charityReceiverCount) {
//					sql_query("UPDATE users SET seedbonus = seedbonus - $points, charity = charity + $points, bonuscomment = ".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
                    $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_GIFT_TO_LOW_SHARE_RATIO, $points. " Points as charity to users with ratio below ".htmlspecialchars(trim($ratiocharity)).".", ['charity' => \Nexus\Database\NexusDB::raw("charity + $points"), ]);
                    $charityPerUser = $points/$charityReceiverCount;
                    sql_query("UPDATE users SET seedbonus = seedbonus + $charityPerUser WHERE enabled='yes' AND 10737418240 < downloaded AND $ratiocharity > uploaded/downloaded") or sqlerr(__FILE__, __LINE__);
                    nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=charity");
                }
                else
                {
                    stdmsg($lang_mybonus['std_sorry'], $lang_mybonus['std_no_users_need_charity']);
                    stdfoot();
                    die;
                }
            }
        }
        elseif($art == "gift_1" && $bonusgift_bonus == 'yes') {
            //=== trade for giving the gift of karma
            $points = $_POST["bonusgift"];
            $message = $_POST["message"];
            //==gift for peeps with no more options
            $usernamegift = sqlesc(trim($_POST["username"]));
            $res = sql_query("SELECT id, bonuscomment FROM users WHERE username=" . $usernamegift);
            $arr = mysql_fetch_assoc($res);
            if (empty($arr)) {
                stdmsg($lang_mybonus['text_error'], $lang_mybonus['text_receiver_not_exists'], 0);
                stdfoot();
                die;
            }
            $useridgift = $arr['id'];
            $userseedbonus = $arr['seedbonus'];
            $receiverbonuscomment = $arr['bonuscomment'];
            if (!is_numeric($points) || $points < $bonusarray['points']) {
                //write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking bonus system",'mod');
                stdmsg($lang_mybonus['text_error'], $lang_mybonus['bonus_amount_not_allowed']);
                stdfoot();
                die();
            }
            if($CURUSER['seedbonus'] >= $points) {
                $points2= number_format($points,1);
//				$bonuscomment = date("Y-m-d") . " - " .$points2. " Points as gift to ".htmlspecialchars(trim($_POST["username"])).".\n " .htmlspecialchars($bonuscomment);

                $aftertaxpoint = $points;
                if ($taxpercentage_bonus)
                    $aftertaxpoint -= $aftertaxpoint * $taxpercentage_bonus * 0.01;
                if ($basictax_bonus)
                    $aftertaxpoint -= $basictax_bonus;

                $points2receiver = number_format($aftertaxpoint,1);
                $newreceiverbonuscomment = date("Y-m-d") . " + " .$points2receiver. " Points (after tax) as a gift from ".($CURUSER["username"]).".\n " .htmlspecialchars($receiverbonuscomment);
                if ($userid==$useridgift){
                    stdmsg($lang_mybonus['text_huh'], $lang_mybonus['text_karma_self_giving_warning'], 0);
                    stdfoot();
                    die;
                }

//				sql_query("UPDATE users SET seedbonus = seedbonus - $points, bonuscomment = ".sqlesc($bonuscomment)." WHERE id = ".sqlesc($userid)) or sqlerr(__FILE__, __LINE__);
                $bonusRep->consumeUserBonus($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_GIFT_TO_SOMEONE, $points2 . " Points as gift to ".htmlspecialchars(trim($_POST["username"])));
                sql_query("UPDATE users SET seedbonus = seedbonus + $aftertaxpoint, bonuscomment = ".sqlesc($newreceiverbonuscomment)." WHERE id = ".sqlesc($useridgift));

                //===send message
                $subject = sqlesc($lang_mybonus_target[get_user_lang($useridgift)]['msg_someone_loves_you']);
                $added = sqlesc(date("Y-m-d H:i:s"));
                $msg = $lang_mybonus_target[get_user_lang($useridgift)]['msg_you_have_been_given'].$points2.$lang_mybonus_target[get_user_lang($useridgift)]['msg_after_tax'].$points2receiver.$lang_mybonus_target[get_user_lang($useridgift)]['msg_karma_points_by'].$CURUSER['username'];
                if ($message)
                    $msg .= "\n".$lang_mybonus_target[get_user_lang($useridgift)]['msg_personal_message_from'].$CURUSER['username'].$lang_mybonus_target[get_user_lang($useridgift)]['msg_colon'].$message;
                $msg = sqlesc($msg);
                sql_query("INSERT INTO messages (sender, subject, receiver, msg, added) VALUES(0, $subject, $useridgift, $msg, $added)") or sqlerr(__FILE__, __LINE__);
                $usernamegift = unesc($_POST["username"]);
                nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=transfer");
            }
            else{
                print("<table width=\"97%\"><tr><td class=\"colhead\" align=\"left\" colspan=\"2\"><h1>".$lang_mybonus['text_oups']."</h1></td></tr>");
                print("<tr><td align=\"left\"></td><td align=\"left\">".$lang_mybonus['text_not_enough_karma']."<br /><br /></td></tr></table>");
            }
        }
        elseif ($art == 'cancel_hr') {
            if (empty($_POST['hr_id'])) {
                stderr("Error","Invalid H&R ID: " . ($_POST['hr_id'] ?? ''), false, false);
            }
            $bonusRep->consumeToCancelHitAndRun($userid, $_POST['hr_id']);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=cancel_hr");
//        } elseif ($art == 'buy_medal') {
//            if (empty($_POST['medal_id'])) {
//                stderr("Error","Invalid Medal ID: " . ($_POST['medal_id'] ?? ''), false, false);
//            }
//            $bonusRep->consumeToBuyMedal($userid, $_POST['medal_id']);
//            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=buy_medal");
        }
        elseif ($art == 'attendance_card') {
            $bonusRep->consumeToBuyAttendanceCard($userid);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=attendance_card");
        }
        elseif ($art == 'rainbow_id') {
            $bonusRep->consumeToBuyRainbowId($userid);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=rainbow_id");
        }
        elseif ($art == 'change_username_card') {
            $bonusRep->consumeToBuyChangeUsernameCard($userid);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=change_username_card");
        }
        elseif ($art == 'loan') {
            $points = $_POST["loanBonus"];
            $loanTurnipDao->customLoan($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD,   $points. " Points for loan.",   []);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=loan_success");
        }
        elseif ($art == 'repayment') {
            $loanTurnipDao->customRepayment($CURUSER['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD,   $points. " Points for repayment.",   []);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=repayment_success");
        }
        elseif ($art == 'buyTurnip') {
            $buyTurnipNum = $_POST["buyTurnipNum"];
            // 检测钱数够不够
            if ($CURUSER['seedbonus'] < $buyTurnipNum * $points) {
                nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=buy_turnip_failed");
            } else {
                // 够
                $loanTurnipDao->customBuyTurnip($CURUSER['id'], $points, $buyTurnipNum, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD,   $points. " Points for buy turnip.",   []);
                nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=buy_turnip_success");
            }
        }
        elseif ($art == 'gacha') {
            $gachaResult = $teamDao->gachaTimes($CURUSER['id'], $bonusarray['times']);
            nexus_redirect("" . get_protocol_prefix() . "$BASEURL/customgame.php?do=".$gachaResult);
        }
    } else {
        print("不知道为什么钱不够");
    }
}


// JavaScript
// JavaScript
// JavaScript
echo "<script>";
// 根据当天的战斗类型确定可以选多少人上场
$TodayVsType = $teamDao->getTodayVsType();
switch ($TodayVsType) {
    case 0:$max=1;break;
    case 1:$max=5;break;
    case 2:$max=999;break;
    default:$max=0;break;
}
// 鼠标点击选中的角色
echo "
// 用list数组记录选中的角色
var list = [];

// 点击角色触发函数
function selectMember(element) {
    // 选中的数量
    var memberSelectedPics = document.querySelectorAll('.memberSelectedPic');
    var count = 0;
    memberSelectedPics.forEach(function(e) {
      if (window.getComputedStyle(e).display === 'block') {
        count++;
      }
    });
    // 最大选中数量
    var max = ".$max.";
    // 显示隐藏
    var memberSelected = element.parentNode.querySelector('.memberSelected');
    var memberSelectedPic = element.parentNode.querySelector('.memberSelectedPic');
    var vsMemberTestInput = document.querySelector('#vs_member_id');
    if (memberSelectedPic.style.display == 'none') {
        // 选中显示
        if (count == max) {return;}
        memberSelected.disabled = false;
        memberSelectedPic.style.display = 'block';
        // list新增元素
        list.push(memberSelected.value);
        vsMemberTestInput.value = list.join(',');
    } else {
        // 取消隐藏
        memberSelected.disabled = true;
        memberSelectedPic.style.display = 'none';
        // list删除元素
        remove = memberSelected.value;
        list = list.filter(function(value, index, arr) {
          return value !== remove; // 过滤掉需要删除的元素
        });
        vsMemberTestInput.value = list.join(',');
    }
}
// 获取提交按钮元素
var submitBtn = document.getElementById('vs_submit');
// 给按钮添加点击事件监听器
submitBtn.addEventListener('click', function(event) {
  
  // 在这里执行你想要触发的函数
  submitMember(event);
});
function submitMember(event) {
    var battleType = ".$TodayVsType.";
    if (battleType == 0) {if(list.length != 1) {event.preventDefault();alert('必须先在英灵殿点击选中1名角色上场');}}
    if (battleType == 1) {if(list.length != 5) {event.preventDefault();alert('必须先在英灵殿点击选中5名角色上场');}}
    if (battleType == 2) {if(list.length < 1) {event.preventDefault();alert('必须先在英灵殿点击选中至少1名角色上场');}}
}
";
echo "</script>";
echo "<script src='js/mybonus.js'></script>";


// 页尾
stdfoot();
?>
