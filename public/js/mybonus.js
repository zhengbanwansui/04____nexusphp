document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM 已经加载完成');
    // 自动播放战斗动画
    battle();
});
// 进行中的角色动画数量
var memberElements = document.getElementsByClassName('bf_member');
var countMemberRunning = memberElements.length;

// 战斗动画构建
function battle() {
    // 获取名为 "do" 的参数值
    // const searchParams = new URLSearchParams(window.location.search);
    // const doValue = searchParams.get('do');
    const doValue = document.getElementById("battleMsgInput").value;

    const battleLogStr = doValue.split("开始战斗@@@")[1];
    battleLogList = battleLogStr.split("@@@");
    var from;
    var to;
    var delay = 0.0;
    var diedList = [];
    for (let log of battleLogList) {
        console.log(log);
        delay += 0.3;
        if (log.startsWith("we")) {
            let attack = log.split("攻击了");
            from = document.getElementById(attack[0]);
            to = document.getElementById(attack[1]);
            if (from.style.animationDelay.length == 0) {
                from.style.animationDelay = delay + 's';
            } else {
                from.style.animationIterationCount = parseInt(window.getComputedStyle(from).getPropertyValue('animation-iteration-count')) + 1;
            }
        }
        if (log.startsWith("enemy")) {
            let attack = log.split("攻击了");
            from = document.getElementById(attack[0]);
            to = document.getElementById(attack[1]);
            if (from.style.animationDelay.length == 0) {
                from.style.animationDelay = delay + 's';
            } else {
                from.style.animationIterationCount = parseInt(window.getComputedStyle(from).getPropertyValue('animation-iteration-count')) + 1;
            }
        }
        if (log.startsWith("died")) {
            let name = log.split("=");
            diedList.push(name[1]);
        }
    }
    console.log("diedList under");
    console.log(diedList);
    console.log("diedList on");

    // 动画播放完执行
    const elements = document.querySelectorAll('.bf_member');
    elements.forEach(element => {
        element.addEventListener('animationend', function() {
            // 动画播放完毕后的操作
            if (diedList.includes(element.id)) {
                element.src="https://pic.ziyuan.wang/user/zhengbanwansui/2023/12/墓碑_731c5e77e1b43.png";
            }
            countMemberRunning = countMemberRunning-1;
            if (countMemberRunning == 0) {
                document.getElementById("battleResultStringLastShow").style.display = "block";
            }
        });
    });

}

// 查看角色
function enableInputs(element) {
    var all = document.querySelectorAll('.member > .memberText > input');
    for (var i = 0; i < all.length; i++) {
        all[i].disabled = true;
    }
    var inputs = element.querySelectorAll('.memberText > input');
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].disabled = false;
    }
}
// 离开角色
function disableInputs(element) {
    var inputs = element.querySelectorAll('.member > .memberText > input');
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].disabled = true;
    }
}

