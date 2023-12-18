<?php
namespace App\Repositories;

use App\Exceptions\NexusException;
use App\Models\BonusLogs;
use App\Models\HitAndRun;
use App\Models\Invite;
use App\Models\Medal;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\TorrentBuyLog;
use App\Models\User;
use App\Models\UserMedal;
use App\Models\UserMeta;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Nexus\Database\NexusDB;

class CustomTeamRepository extends BaseRepository
{

    // 这里开始写抽卡相关的逻辑, 先把抽卡表的info字段解析逻辑写出来吧
    // 这里开始写抽卡相关的逻辑, 先把抽卡表的info字段解析逻辑写出来吧
    // 这里开始写抽卡相关的逻辑, 先把抽卡表的info字段解析逻辑写出来吧
    public function gachaTimes($user, $times) {
        global $globalGachaResult;
        $globalGachaResult = "抽卡结果未赋值....";
        NexusDB::transaction(function () use ($user, $times) {
            $gachaPrice = $this->gachaOncePrice() * $times;
            // 获取用户信息
            $user = $this->getUser($user);// echo $user->username; echo $user->seedbonus;
            // 校验钱数
            if ($user->seedbonus < $gachaPrice) {
                throw new NexusException("钱不够, 买不起!!!钱不够, 买不起!!!钱不够, 买不起!!!", 666);
            }
            // 获取用户已经获得的碎片
            $pieceDO = NexusDB::table("custom_team_piece")->where('user_id', $user->id)->first();
            if ($pieceDO == null) {
                $pieceDO = [ // 碎片为空则插入一条
                    "user_id" => $user->id,
                    "username" => $user->username,
                    "created_at" => now()->toDateTimeString(),
                    "updated_at" => now()->toDateTimeString(),
                    "info" => ""];NexusDB::table("custom_team_piece")->insert($pieceDO);
                $pieceDO = NexusDB::table("custom_team_piece")->where('user_id', $user->id)->first();
            }
            // 碎片格式转换
            $pieceArray = $this->changeInfoStringToArray($pieceDO->info);
            // 获取扭蛋角色预设信息
            $teamArray = $this->getTeamArray();
            // 扭蛋$times个角色名 当期up角色概率提升
            $randKeys = $this->getRandomKeys($teamArray, $times);
            global $globalGachaResult;
            $globalGachaResult =
                "抽卡结果: 次数=".$times.
                " 获得物品=".implode(',', array_map(function($randKeys) {return $randKeys . '碎片x1';}, $randKeys));
            // 增加到碎片
            foreach ($randKeys as $key) {
                if (array_key_exists($key, $pieceArray)) {
                    $pieceArray[$key] = intval($pieceArray[$key]) + 1;
                } else {
                    $pieceArray[$key] = 1;
                }
            }
            // 获取用户已有的team角色
            $teamDOList = NexusDB::table("custom_team_member")->where('user_id', $user->id)->get();
            // 校验是否组成新角色, 如有, 插入新的到角色表
            foreach ($pieceArray as $key => $value) {
                if ($pieceArray[$key] >= 10) {
                    // 且用户没有此角色
                    $hasMember = 0;
                    foreach ($teamDOList as $teamDO) {
                        if ($teamDO->name == $key) {
                            $hasMember = 1;
                        }
                    }
                    if ($hasMember == 0) {
                        // 新增角色信息一条
                        $newMember = [
                            "user_id" => $user->id,
                            "username" => $user->username,
                            "created_at" => now()->toDateTimeString(),
                            "updated_at" => now()->toDateTimeString(),
                            "info" => $teamArray[$key],
                            "name" => $key,
                            "lv" => 0,
                            "exp" => 0
                        ];
                        $newMember = $this->generateHpAtkDef($newMember);
                        NexusDB::table("custom_team_member")->insert($newMember);
                        // todo shoutbox
                        $this->shoutbox($user->id, "恭喜".$user->username."获得角色".
                            str_replace("<br>", ",", $newMember['info']).
                            " HP=".$newMember['hp']." ATK=".$newMember['atk']." DEF=".$newMember['def']);
                    }
                }
            }
            // 更新扭蛋信息
            $backToInfo = $this->arrayBackToString($pieceArray);
            NexusDB::table("custom_team_piece")->where('user_id', $user->id)->update([
                "info"=> $backToInfo
            ]);
            // 更新用户钱数
            NexusDB::table("users")->where('id', $user->id)->update([
                "seedbonus" => bcsub($user->seedbonus, $gachaPrice)
            ]);
        });
        return $globalGachaResult;
    }

    // 投喂食物, 获得经验, 升级角色
    // 投喂食物, 获得经验, 升级角色
    // 投喂食物, 获得经验, 升级角色
    public function eatFoodAddExp($user, $memberId, $memberName, $foodNum, $oneFoodPrice) {
        global $globalEatResult;
        $globalEatResult = "投喂结果未赋值....";
        NexusDB::transaction(function () use ($user, $memberId, $memberName, $foodNum, $oneFoodPrice) {
            global $globalEatResult;

            // 获取用户信息
            $user = $this->getUser($user);
            // 算总价格
            $totalPrice = bcmul($foodNum, $oneFoodPrice);
            // 看钱够不够
            if ($user->seedbonus < $totalPrice) {
                throw new NexusException("投喂总共需要".$totalPrice."象草, 你的余额是".$user->seedbonus, 666);
            }
            // 扣钱
            $this->updateUserById($user->id, ["seedbonus" => bcsub($user->seedbonus, $totalPrice)]);
            // 获取角色信息
            $member = $this->getTeamMember($memberId);
            $today_start = date('Y-m-d 00:00:00');
            if ($member->last_feed_at == $today_start) {
                $globalEatResult = "投喂结果: 吃不下了";
                return;
            }
            // 增加经验
            $currentExp = $member->exp + $foodNum;
            $currentLv = $member->lv;
            $updateMemberDO = ["exp"=>$currentExp];
            $globalEatResult = "投喂结果: ".$memberName."开心地吃掉了你投喂的食物 "."[经验增加".$foodNum."] ";
            // 看有没有升级
            if ($currentExp >= $member->lv + 10) {
                // 升级了
                $currentExp = $currentExp - $currentLv - 10;
                $currentLv = $currentLv + 1;
                $globalEatResult = $globalEatResult."等级提升到".$currentLv. " 能力提升了!";
                $updateMemberDO = ["hp"=>$member->hp,"atk"=>$member->atk,"def"=>$member->def, "lv"=>$currentLv, "exp"=>$currentExp];
                $updateMemberDO = $this->generateHpAtkDef($updateMemberDO);
            }
            // 更新角色信息(等级, 属性)
            $updateMemberDO["last_feed_at"] = $today_start;
            NexusDB::table("custom_team_member")->where("id", $memberId)->update($updateMemberDO);
        });
        return $globalEatResult;
    }

    // 随机对战
    // 随机对战
    // 随机对战
    public function vs($user) {

    }

    // 抽卡主方法, 从众多角色名称中抽$times次
    // 抽卡主方法, 从众多角色名称中抽$times次
    // 抽卡主方法, 从众多角色名称中抽$times次
    function getRandomKeys($array, $times) {
        $keys = array();
        for ($i = 0; $i < $times; $i++) {
            $randKey = array_rand($array);
            if (rand(1, 100) <= 20) {
                $keys[] = $this->getUpMemberName();// 抽到up角色
            } else {
                $keys[] = $randKey; // 抽到普通角色
            }

        }
        return $keys;
    }

    public function getUpMemberName() {
        $array=$this->getTeamArray();
        // 总天数
        $currentDays = floor(time() / (60 * 60 * 24));
        // 第几条
        $index = $currentDays % count($array);
        // 根据index取到key名
        $upMemberName = array_keys($array)[$index];
        return $upMemberName;
    }
    function gachaOncePrice() {
        return 6480;
    }

    function changeInfoStringToArray($string) {
        if (empty($string)) {
            return [];
        }
        // 解析字符串
        $pairs = explode(",", $string);
        $data = [];
        foreach ($pairs as $pair) {
            list($key, $value) = explode("=", $pair);
            $data[$key] = $value;
        }
        // 输出结果
        return $data;
    }

    function arrayBackToString($infoArray) {
        $pairs = [];
        foreach ($infoArray as $key => $value) {
            $pair = $key . '=' . $value;
            $pairs[] = $pair;
        }
        $string = implode(',', $pairs);
        return $string;
    }

    // 如果是Lv1生成初始数值
    // 如果不是, 随机增加数值
    function generateHpAtkDef($array) {
        if ($array["lv"] == 0) {
            $array["lv"] = 1;
            // 生成随机的 hp 属性
            $hp = mt_rand(30, 60);
            $array['hp'] = $hp;
            // 根据 hp 值生成随机的 atk 属性
            if ($hp < 45) {
                $atk = mt_rand(15, 20);
            } else {
                $atk = mt_rand(12, 15);
            }
            $array['atk'] = $atk;
            // 生成随机的 def 属性
            $def = mt_rand(6, 11);
            $array['def'] = $def;
        } else {
            // 随机选择升级的属性
            $upgradeAttribute = mt_rand(1, 3);
            // 根据选择的属性进行升级
            switch ($upgradeAttribute) {
                case 1:
                    // 升级 hp
                    $hpIncrease = mt_rand(12, 22);
                    $array['hp'] += $hpIncrease;
                    break;
                case 2:
                    // 升级 atk
                    $atkIncrease = mt_rand(1, 4);
                    $array['atk'] += $atkIncrease;
                    break;
                case 3:
                    // 升级 def
                    $defIncrease = mt_rand(1, 3);
                    $array['def'] += $defIncrease;
                    break;
            }
        }
        return $array;
    }

    public function getTeamArray() {
        $array = [
            "莎莉"=>"莎莉 [象岛居民]♀<br>普通B型, 象, 生日1月28日<br>口头禅：莎啦啦",
            "露露"=>"露露 [象岛居民]♀<br>成熟B型, 象, 生日1月20日<br>口头禅：勇",
            "巨巨"=>"巨巨 [象岛居民]♂<br>悠闲B型, 象, 生日7月14日<br>口头禅：象象",
            "阿三"=>"阿三 [象岛居民]♂<br>悠闲B型, 象, 生日10月3日<br>口头禅：哈啊",
            "艾勒芬"=>"艾勒芬 [象岛居民]♀<br>成熟B型, 象, 生日12月8日<br>口头禅：鲁鲁",
            "鲨鲨"=>"鲨鲨 [结缘限定]♀<br>元气A型, 鱼, 生日6月20日<br>口头禅：Shaaaaaark！",
            "啡卡"=>"啡卡 [象岛居民]♀<br>元气A型, 象, 生日3月6日<br>口头禅：大耳",
            "茉莉"=>"茉莉 [象岛居民]♀<br>普通A型, 象, 生日11月18日<br>口头禅：呼",
            "保罗"=>"保罗 [象岛居民]♂<br>悠闲A型, 象, 生日5月5日<br>口头禅：保罗",
            "庞克斯"=>"庞克斯 [象岛居民]♂<br>暴躁A型, 象, 生日6月9日<br>口头禅：摇滚",
            "叶天帝"=>"叶天帝 [结缘限定]♂<br>悠闲A型, 人, 生日10月29日<br>口头禅：可叹,落叶飘零~",
            "大大"=>"大大 [象岛居民]♂<br>运动B型, 象, 生日3月23日<br>口头禅：嘻嘻",
            "泡芙"=>"泡芙 [象岛居民]♀<br>普通A型, 象, 生日5月12日<br>口头禅：啦啷",
            "阿原"=>"阿原 [象岛居民]♂<br>悠闲A型, 象, 生日9月7日<br>口头禅：毛毛",
            "麒麟9000s"=>"麒麟9000s [结缘限定]♀<br>成熟B型, 麒麟, 生日12月2日<br>口头禅：安逸的氛围…喜欢",
        ];
        return $array;
    }
    public function getTeamPic() {
        $array = [
            "莎莉"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_5233f1a636b06.png",
            "露露"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_05c21d1df69cb.png",
            "巨巨"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_7fa282a1a8efa.png",
            "阿三"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_f2da408d055d2.png",
            "艾勒芬"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_37851e22694ab.png",
            "啡卡"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_54f476df92b9a.png",
            "茉莉"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_bb5d892a978a4.png",
            "保罗"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_7506de8c9d280.png",
            "庞克斯"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_e148cd18f920f.png",
            "大大"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_aba7f6d9e0272.png",
            "泡芙"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_0c8eb01cc068f.png",
            "阿原"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_1898d83ebfdbc.png",
            "鲨鲨"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_2691dcc2ccf8c.png",
            "叶天帝"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/_1d1abd4049729.png",
            "麒麟9000s"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/9000_4054ea98717fc.png"
        ];
        return $array;
    }

    public function shoutbox($user_id, $text) {
        $shoutDO = [
            "userid"=>$user_id,
            "date"=>time(),
            "text"=>$text,
            "type"=>"sb"
        ];
        NexusDB::table("shoutbox")->insert($shoutDO);
    }

    function updateUserById($id, $updateArray) {
        NexusDB::table("users")->where("id",$id)->update($updateArray);
    }

    function getTeamMember($id) {
        return NexusDB::table("custom_team_member")->where("id",$id)->first();
    }
}