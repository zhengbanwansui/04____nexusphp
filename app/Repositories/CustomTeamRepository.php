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
    public $lvUpCostExp = 5;
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
            $globalGachaResult = "抽卡结果: 次数=".$times. " "."获得物品=".implode(',', array_map(function($randKeys) {return $randKeys . '碎片x1';}, $randKeys));
            // 增加到碎片
            foreach ($randKeys as $key) {
                if (array_key_exists($key, $pieceArray)) {
                    $pieceArray[$key] = intval($pieceArray[$key]) + 1;
                } else {
                    $pieceArray[$key] = 1;
                }
            }
            // 获取用户已有的team角色
            $teamDOList = NexusDB::table("custom_team_member")->where('user_id', $user->id)->orderByDesc('lv')->get();
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
                        // shoutbox
                        $infoShout = str_replace("<br>", ",", $newMember['info']);
                        $parts = explode(',', $infoShout, 2);
                        if (strpos($newMember['info'], "限定")) {
                            $b1 = "<b class='rainbow'>";
                        } else {
                            $b1 = "<b style='color: #ff4242c9'>";
                        }
                        $this->shoutbox("恭喜".custom_get_user_name($user->id, false)."获得". $b1.$parts[0]."</b>");
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

    // 批量投喂
    public function bulkEat($userId, $teamMember, $memberIndexStr, $oneFoodPrice) {
        if ($memberIndexStr == null) return "投喂结果：未选择投喂的角色！";
        $memberIndexs = explode(",", $memberIndexStr);
        $results = "";
        foreach ($memberIndexs as $index) {
            $memberObj = $teamMember[intval($index)];
            $results .= "@@@";
            $results .= $this->eatFoodAddExp($userId, $memberObj->id, 0, $oneFoodPrice);
        }

        return $results;
    }

    // 单个角色, 投喂食物, 获得经验, 升级角色
    // 单个角色, 投喂食物, 获得经验, 升级角色
    // 单个角色, 投喂食物, 获得经验, 升级角色
    public function eatFoodAddExp($userId, $memberId, $foodNum, $oneFoodPrice) {
        global $globalEatResult;
        $globalEatResult = "";
        if ($oneFoodPrice == null || $memberId == null) {
            $globalEatResult = "投喂失败....错误退出";
            return $globalEatResult;
        }
        // 获取用户信息
        $user = $this->getUser($userId);
        // 获取角色信息
        $member = $this->getTeamMemberById($memberId);
        NexusDB::transaction(function () use ($user, $memberId, $member, $foodNum, $oneFoodPrice) {
            global $globalEatResult;
            $memberName = $member->name;
            if ($foodNum == 0) {
                // 未定义投喂数量, 按照最大投喂数量投喂
                $foodNum = floor($member->lv / 5) * 1 + $this->lvUpCostExp;
            }
            // 算总价格
            $totalPrice = bcmul($foodNum, $oneFoodPrice);
            // 看钱够不够
            if ($user->seedbonus < $totalPrice) {
                throw new NexusException("投喂总共需要".$totalPrice."象草, 你的余额是".$user->seedbonus, 666);
            }
            // 扣钱
            $this->updateUserById($user->id, ["seedbonus" => bcsub($user->seedbonus, $totalPrice)]);


            $today_start = date('Y-m-d 00:00:00');
            if ($member->last_feed_at == $today_start) {
                $globalEatResult = "投喂结果: ".$memberName."吃不下了";
                return $globalEatResult;
            }
            // 增加经验
            $currentExp = $member->exp + $foodNum;
            $currentLv = $member->lv;
            $updateMemberDO = ["exp"=>$currentExp];
            $globalEatResult = "投喂结果: ".$memberName."开心地吃掉了你投喂的食物 "."[经验增加".$foodNum."] ";
            // 升级所需经验
            $upCost = floor($member->lv / 5) * 1 + $this->lvUpCostExp;
            // 看有没有升级
            $luckyNumber = mt_rand(0, 99);
            if ($currentExp >= $upCost) {
                if (strpos($member->info, "限定")) {$b1 = "<b class='rainbow'>";} else {$b1 = "<b style='color: #ff4242c9'>";}
                // 升级了
                $currentExp = $currentExp - $upCost;
                $updateMemberDO = ["hp"=>$member->hp,"atk"=>$member->atk,"def"=>$member->def, "lv"=>$currentLv, "exp"=>$currentExp];
                // 生成一个 0 到 99 之间的随机数, 10% 升两级, 2% 升十级
                if ($luckyNumber < 2) {//2% 升十级
                    for ($i = 0; $i < 10; $i++) {
                        $updateMemberDO = $this->generateHpAtkDef($updateMemberDO);
                    }
                    $globalEatResult = $globalEatResult." 触发了*小象的庆典*, 等级%2B10 ";
                    $this->shoutbox("恭喜".custom_get_user_name($user->id, false)."的".$b1.$member->name."</b>触发了<b class='rainbow'>小象的庆典</b>，连升10级~");
                    $updateMemberDO['lv']+=10;
                } else if ($luckyNumber < 12) {//10% 升两级
                    $updateMemberDO = $this->generateHpAtkDef($updateMemberDO);
                    $updateMemberDO = $this->generateHpAtkDef($updateMemberDO);
                    $globalEatResult = $globalEatResult." 触发了*小象的赐福*, 等级%2B2 ";
                    $this->shoutbox("恭喜".custom_get_user_name($user->id, false)."的".$b1.$member->name."</b>触发了<b style='color: #ff4242c9'>小象的赐福</b>，连升2级~");
                    $updateMemberDO['lv']+=2;
                } else {
                    $updateMemberDO = $this->generateHpAtkDef($updateMemberDO);
                    $globalEatResult = $globalEatResult." 等级%2B1 ";
                    $updateMemberDO['lv']++;
                }
                $globalEatResult = $globalEatResult." 等级提升到".$updateMemberDO['lv']. " 角色的某种能力提升了!";
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
    public function vs($user, $teamMember, $memberIndexStr) {
        if ($this->getTodayBattleCount($user) == 3) {
            throw new NexusException("进入战斗次数达到上限了");
        }
        if (strlen($memberIndexStr) == 0) {
            throw new NexusException("选中上场的角色为空");
        }
        // 获取用户信息
        $user = $this->getUser($user);
        $we = [];
        foreach (explode(",", $memberIndexStr) as $index) {
            $m = $teamMember[intval($index)];
            $we[] = $m;
        }
        NexusDB::transaction(function () use ($user, &$result, $we, $memberIndexStr) {
            $result = "战斗结果: @@@战斗模式: ".$this->getVsTypeList()[$this->getTodayVsType()];
            switch ($this->getTodayVsType()) {
                case 0:// 1v1
                    if(count($we) != 1) {
                        throw new NexusException("选中上场的角色数量应该为1");
                    }
                    // 获取敌人
                    $enemy = $this->getEnemyArray($user, 1);
                    // 战斗
                    $record = $this->vsTemplate($user, $we, $enemy, $result);
                    // 奖励
                    if ($record['win'] == 1) {
                        $prize = 888;
                    } else {
                        $prize = 168;
                    }
                    break;
                case 1:// 5v5
                    if(count($we) != 5) {
                        throw new NexusException("   选中上场的角色数量应该为5个 你上场了=".count($we)."个   ");
                    }
                    // 获取敌人
                    $enemy = $this->getEnemyArray($user, 5);
                    // 战斗
                    $record = $this->vsTemplate($user, $we, $enemy, $result);
                    // 奖励
                    if ($record['win'] == 1) {
                        $prize = 1234;
                    } else {
                        $prize = 218;
                    }
                    break;
                case 2:// NvBoss
                    if(count($we) < 1) {
                        throw new NexusException("选中上场的角色数量应该至少为1个 你上场了=".count($we)." str=".$memberIndexStr);
                    }
                    // 获取敌人
                    $enemy = $this->getEnemyArray($user, 1);
                    $enemy[0]->hp = 10000;
                    $enemy[0]->def = 8;
                    $enemy[0]->atk = 15;
                    $enemy[0]->username = "站长";
                    // 战斗
                    $record = $this->vsTemplate($user, $we, $enemy, $result);
                    $totalDamage = $record['totalDamage'];
                    $idOneSeedbonus = NexusDB::table("users")->where("id", 1)->first()->seedbonus;
                    $prize = $idOneSeedbonus/130000 + $totalDamage / 3;
                    break;
            }
            $timeId = time();
            // 删除今天以前旧的战斗记录
//            throw new NexusException("[[[uid=".$user->id." date=".date('Y-m-d')."]]]",666);
            NexusDB::table("custom_team_battle")
                ->where('user_id', '=', $user->id)
                ->where('date', '!=', date('Y-m-d 00:00:00'))
                ->delete();
            // 入库新的战斗记录
            NexusDB::table("custom_team_battle")->insert([
                "id"=>$timeId,
                "user_id"=>$user->id,
                "info"=>$result,
                "win"=>$record['win'],
                "prize"=>$prize,
                "date"=>date('Y-m-d 00:00:00')
            ]);
            // 增加魔力奖励
            NexusDB::table("users")->where("id", $user->id)->update(
                ["seedbonus"=>bcadd($user->seedbonus, $prize)]
            );
            $result = "战斗结果=".$timeId;
        });
        return $result;
    }

    // 战斗模板方法, 使用此方法可以处理任何形式的战斗, 方便统一处理
    // 战斗模板方法, 使用此方法可以处理任何形式的战斗, 方便统一处理
    // 战斗模板方法, 使用此方法可以处理任何形式的战斗, 方便统一处理
    // result是战斗记录, 每一行开头用@@@换行
    function vsTemplate($user, $we, $enemy, &$result) {
        // 战报记录对象
        $record = [
            "totalDamage"=>0,
            "win"=>2,// 0输1赢2平
        ];

        $result .= "@@@我方上场角色: ";
        foreach ($we as $one) {
            $result .= '['.$one->name . '] ';
        }
        $result .= "@@@VS";
        $result .= "@@@敌方上场角色: ";
        foreach ($enemy as $one) {
            $result .= $one->username."的"."[".$one->name."]";
        }
        $result .= "@@@开始战斗";
        // we和enemy的角色不管有多少个都按照一套战斗逻辑去写

        // 根据等级排序生成一次行动轨迹之后一直用就行
        $all = array_merge($we, $enemy);
        usort($all, function ($a, $b) {
            return $b->lv - $a->lv;
        });
        // we和enemy转为{name=>对象}的关联数组
        $associatedWe = [];
        $associatedEnemy = [];
        foreach ($we as $character) {
            $associatedWe[$character->name] = $character;
        }
        foreach ($enemy as $character) {
            $associatedEnemy[$character->name] = $character;
        }

        $turn = 0;
        while (true) {
            $turn++;
//            $result .= "@@@回合开始"; 暂时不需要显示回合了
            // 所有角色依据顺序各动一次
            foreach ($all as $sortOne) {
                if ($sortOne->username == $user->username) {
                    // 我方$run角色行动
                    $run = $associatedWe[$sortOne->name];
                    if ($run->hp > 0) {
                        $result .= "@@@we_";
                        $targetKey = $this->getRandKeyAlive($associatedEnemy);
                        $associatedEnemy[$targetKey]->hp -= ($run->atk - $associatedEnemy[$targetKey]->def);
                        $record['totalDamage'] += ($run->atk - $associatedEnemy[$targetKey]->def);
                        $result .= $run->name."攻击了enemy_".$associatedEnemy[$targetKey]->name;
                        // 单体死亡检测
                        if ($associatedEnemy[$targetKey]->hp <=0) {
                            $result .= "@@@died=enemy_".$associatedEnemy[$targetKey]->name;
                        }
                        // 团队死亡检测
                        $fin = 1;
                        foreach ($associatedEnemy as $o) {
                            if ($o->hp > 0) {
                                $fin = 0;
                            }
                        }
                        if ($fin == 1) {
//                            $result .= "@@@战斗结束我方胜利";
                            $record['win'] = 1;
                            return $record;
                        }
                    }
                }
                else {
                    // 敌方$run角色行动
                    $run = $associatedEnemy[$sortOne->name];
                    if ($run->hp > 0) {
                        $result .= "@@@enemy_";
                        $targetKey = $this->getRandKeyAlive($associatedWe);
                        $associatedWe[$targetKey]->hp -= ($run->atk - $associatedWe[$targetKey]->def);
                        $result .= $run->name."攻击了we_".$associatedWe[$targetKey]->name;
                        // 单体死亡检测
                        if ($associatedWe[$targetKey]->hp <=0) {
                            $result .= "@@@died=we_".$associatedWe[$targetKey]->name;
                        }
                        // 团队死亡检测
                        $fin = 1;
                        foreach ($associatedWe as $o) {
                            if ($o->hp > 0) {
                                $fin = 0;
                            }
                        }
                        if ($fin == 1) {
//                            $result .= "@@@战斗结束敌方胜利";
                            $record['win'] = 0;
                            return $record;
                        }
                    }
                }
            }
            // 测试只走一回合
            if ($turn == 30) {
//                $result .= "@@@战斗结束平局";
                $record['win'] = 2;
                return $record;
            }
        }
    }

    function getRandKeyAlive($associated) {
        $associated = array_filter($associated, function ($value) {
            return $value->hp > 0;
        });
        $keys = array_keys($associated);
        $random_key = $keys[array_rand($keys)];
        return $random_key;
    }

    function getEnemyArray($user, $num) {
        $randomMembers = NexusDB::table("custom_team_member")
        ->where('user_id', '!=', $user->id)
        ->inRandomOrder()
        ->limit($num)
        ->get();
        $array = [];
        foreach ($randomMembers as $one) {
            $array[] = $one;
        }
        return $array;
    }

    // 抽卡主方法, 从众多角色名称中抽$times次
    // 抽卡主方法, 从众多角色名称中抽$times次
    // 抽卡主方法, 从众多角色名称中抽$times次
    function getRandomKeys($array, $times) {
        $keys = array();
        for ($i = 0; $i < $times; $i++) {
            $randKey = array_rand($array);
            if (rand(1, 100) <= 16) {
                $keys[] = $this->getUpMemberName();// 抽到up角色
            } else {
                $keys[] = $randKey; // 抽到普通角色
            }
        }
        if (!in_array($this->getUpMemberName(), $keys) && $times == 10) {
            $keys = array_replace($keys, array(0 => $this->getUpMemberName()));
        }
        return $keys;
    }

    public function getUpMemberName() {
        $array=$this->getUpArray();
        // 总天数
        $currentDays = floor(time() / (60 * 60 * 24));
        // 第几条
        $index = $currentDays % count($array);
        // 根据index取到key名
        $upMemberName = array_keys($array)[$index];
        return $upMemberName;
    }

    public function gachaOncePrice() {
        $start_date = strtotime('2024-03-28 00:00:00');
        $today_date = strtotime(date('Y-m-d 00:00:00'));
        $days_since_start = floor(($today_date - $start_date) / (60 * 60 * 24));
        return 8888 + $days_since_start * 10;
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
            // 限定角色初始属性更高一点
            if (strpos($array['info'], "限定")) {
                $array['atk'] = $array['atk'] + 8;
                $array['hp'] = $array['hp'] + 20;
                $array['def'] = $array['def'] + 4;
            }
        } else {
            // 随机选择升级的属性
            $upgradeAttribute = mt_rand(1, 5);
            // 根据选择的属性进行升级
            switch ($upgradeAttribute) {
                case 1:
                case 2:
                    // 升级 hp
                    $hpIncrease = mt_rand(12, 22);
                    $array['hp'] += $hpIncrease;
                    break;
                case 3:
                case 4:
                    // 升级 atk
                    $atkIncrease = mt_rand(1, 4);
                    $array['atk'] += $atkIncrease;
                    break;
                case 5:
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
            "啡卡"=>"啡卡 [象岛居民]♀<br>元气A型, 象, 生日3月6日<br>口头禅：大耳",
            "茉莉"=>"茉莉 [象岛居民]♀<br>普通A型, 象, 生日11月18日<br>口头禅：呼",
            "保罗"=>"保罗 [象岛居民]♂<br>悠闲A型, 象, 生日5月5日<br>口头禅：保罗",
            "庞克斯"=>"庞克斯 [象岛居民]♂<br>暴躁A型, 象, 生日6月9日<br>口头禅：摇滚",
            "大大"=>"大大 [象岛居民]♂<br>运动B型, 象, 生日3月23日<br>口头禅：嘻嘻",
            "泡芙"=>"泡芙 [象岛居民]♀<br>普通A型, 象, 生日5月12日<br>口头禅：啦啷",
            "阿原"=>"阿原 [象岛居民]♂<br>悠闲A型, 象, 生日9月7日<br>口头禅：毛毛",
            "鲨鲨"=>"鲨鲨 [结缘限定]♀<br>元气A型, 鱼, 生日6月20日<br>口头禅：Shaaaaaark！",
            "叶天帝"=>"叶天帝 [结缘限定]♂<br>悠闲A型, 人, 生日10月29日<br>口头禅：可叹,落叶飘零~",
            "麒麟9000s"=>"麒麟9000s [结缘限定]♀<br>成熟B型, 麒麟, 生日12月2日<br>口头禅：安逸的氛围…喜欢",
            "小蝶"=>"小蝶 [结缘限定]♀<br>成熟B型, 蝴蝶, 生日2月24日<br>口头禅：摩西摩西~",
            "恋恋"=>"恋恋 [结缘限定]♀<br>运动A型, 北极熊, 生日9月15日<br>口头禅：加油",
        ];
        return $array;
    }
    public function getUpArray() {
        $array = [
            "阿三"=>"加入此列表的角色会轮替up",
            "艾勒芬"=>"加入此列表的角色会轮替up",
            "啡卡"=>"加入此列表的角色会轮替up",
            "茉莉"=>"加入此列表的角色会轮替up",
            "保罗"=>"26加入此列表的角色会轮替up",
            "庞克斯"=>"27加入此列表的角色会轮替up",
            "大大"=>"28加入此列表的角色会轮替up",
            "泡芙"=>"29加入此列表的角色会轮替up",
            "莎莉"=>"加入此列表的角色会轮替up",
            "露露"=>"加入此列表的角色会轮替up",
            "巨巨"=>"加入此列表的角色会轮替up",
            "阿原"=>"加入此列表的角色会轮替up",
            // "叶天帝"=>"31加入此列表的角色会轮替up",
            // "麒麟9000s"=>"1加入此列表的角色会轮替up",
            // "鲨鲨"=>"30鲨鲨 加入此列表的角色会轮替up",
            // "小蝶"=>"30鲨鲨 加入此列表的角色会轮替up",
            // "恋恋"=>"30鲨鲨 加入此列表的角色会轮替up",
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
            "麒麟9000s"=>"https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/9000_4054ea98717fc.png",
            "小蝶"=>"https://img.ptvicomo.net/pic/2024/04/14/661bf25d759df.png",
            "恋恋"=>"https://img.ptvicomo.net/pic/2024/04/14/661bf5886cc6b.png",
        ];
        return $array;
    }

    public function shoutbox($text) {
        $shoutDO = [
            "userid"=>1,
            "date"=>time(),
            "text"=>$text,
            "type"=>"sb"
        ];
        NexusDB::table("custom_broadcastbox")->insert($shoutDO);
    }

    function updateUserById($id, $updateArray) {
        NexusDB::table("users")->where("id",$id)->update($updateArray);
    }

    public function getTodayVsType() {
        $weekDay = intval(getWeekDayNumber());
        switch ($weekDay) {
            case 1:
            case 3:
                return 0;
            case 2:
            case 4:
                return 1;
            case 5:
            case 6:
            case 7:
                return 2;
        }
    }
    public function getVsTypeList() {
        $arr = [
            "锋芒交错 - 1v1", // 用户手动选一个角色参与1v1
            "龙与凤的抗衡 - 团战 5v5", // 用户手动选择五个角色参与5v5
            "世界boss - 对抗Sysrous" // 用户全体角色一起上
        ];
        return $arr;
    }

    // 根据用户ID, 从大到小等级获取一个用户的所有角色
    // 根据用户ID, 从大到小等级获取一个用户的所有角色
    // 根据用户ID, 从大到小等级获取一个用户的所有角色
    function listTeamMemberByUserId($userId) {
        global $CURUSER;
        $record = NexusDB::table("custom_team_member")
            ->where('user_id', $userId)
            ->orderByDesc('lv')
            ->get();
        return $record;
    }

    // 根据id获取一个角色的信息
    // 根据id获取一个角色的信息
    // 根据id获取一个角色的信息
    public function getTeamMemberById($id) {
        $sqlResult = NexusDB::table("custom_team_member")->where("id",intval($id))->first();
        return $sqlResult;
    }

    // ??? 可删
//    function getTodayBattleRecord() {
//        $today = date('Y-m-d');
//    }

    public function getTodayBattleCount($id) {
        $bats = NexusDB::table("custom_team_battle")
            ->where('user_id', '=', $id)
            ->where('date', '=', date('Y-m-d 00:00:00'))->get();
        return count($bats);
    }
//    function getChapter() {
//        $array = [
//            "竞技之魂：起源之旅",
//            "角斗士的崛起：征服竞技场",
//            "力量之路：拯救小象王国",
//            "迷失的竞技场：向未知挑战",
//            "神秘传承：解开古老的谜题",
//            "勇敢的战士：保卫家园的战斗",
//            "猎人的挑战：捕捉稀有的巨兽",
//            "小象学院：培养下一代竞技英雄",
//            "传奇决斗：与英雄对决解锁传说",
//            "黑暗威胁：摧毁邪恶势力的阴谋",
//            "竞技盟约：建立竞技联盟",
//            "英雄试炼：向最强战士发起挑战",
//            "荣耀归来：重返竞技场之巅",
//            "神秘遗迹：探索失落的战场",
//            "毁灭之战：对抗毁灭者的入侵",
//            "王者传承：夺取象岛王位的血脉之争",
//            "跨越边界：穿越时空展开史诗对决",
//            "幻灭幽影：揭开竞技场的幽暗秘密",
//            "龙战：挑战恶龙保卫王国的传说",
//            "无尽挑战：面对不停增强的敌人"
//        ];
//        return $array;
//    }
}