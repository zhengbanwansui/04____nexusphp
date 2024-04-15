var mySwiper = new Swiper ('.swiper', {
direction: 'horizontal', // 垂直切换选项
loop: true, // 循环模式选项
autoplay: true,
delay: 5000,
// // 如果需要分页器
// pagination: {
// el: '.swiper-pagination',
// },
// 如果需要前进后退按钮
navigation: {
nextEl: '.swiper-button-next',
prevEl: '.swiper-button-prev',
},
// 如果需要滚动条
// scrollbar: {
// el: '.swiper-scrollbar',
// },
})

/**
 * 主页底部空间补齐
 */
var menuAddLeft = 0;
var menuAddRight = 0;
function fillBottomBlank() {
    var menuLeft = document.getElementsByClassName('menuLeft')[0];
    var menuRight = document.getElementsByClassName('menuRight')[0];
    var lastChildLeft = menuLeft.lastElementChild;
    var lastChildRight = menuRight.lastElementChild;
    if (menuRight.offsetHeight > menuLeft.offsetHeight) {
        const add = menuRight.offsetHeight - menuLeft.offsetHeight;
        lastChildLeft.style.height = lastChildLeft.offsetHeight + add + 'px';
        menuAddLeft += add;
    } else if (menuLeft.offsetHeight > menuRight.offsetHeight) {
        const add = menuLeft.offsetHeight - menuRight.offsetHeight;
        lastChildRight.style.height = lastChildRight.offsetHeight + add + 'px';
        menuAddRight += add;
    }
    if (menuAddLeft != 0 && menuAddRight != 0) {
        const bothSub = menuAddLeft >= menuAddRight ? menuAddRight : menuAddLeft;
        lastChildLeft.style.height = lastChildLeft.offsetHeight - bothSub + 'px';
        lastChildRight.style.height =lastChildRight.offsetHeight - bothSub + 'px';
        menuAddLeft -= bothSub;
        menuAddRight -= bothSub;
    }
}

/**
 * 每秒执行调整底部对齐
 */
setInterval(function() {fillBottomBlank();}, 1000);

/**
 * 获取cookie中的配色
 */
// 获取所有 cookie
let cookies = document.cookie;
// 分割 cookie 字符串
let cookieArr = cookies.split(";");
// 遍历 cookieArr 数组
for (let i = 0; i < cookieArr.length; i++) {
    let cookiePair = cookieArr[i].split("=");
    let name = cookiePair[0].trim();
    let value = cookiePair[1].trim();
    // 查找名为mainmenuColor的cookie
    if (name === "mainmenuColor") {
        document.getElementById("mainmenu").style.backgroundColor = value;
    }
    if (name === "outerColor") {
        document.getElementById("outer").style.backgroundColor = value;
    }
}

/**
 * 用户手动修改自定义配色
 */
function changeMainmenuColor() {
    var colorValue = document.getElementById("mainmenuColor").value;
    if (colorValue) {
        document.cookie = "mainmenuColor=" + colorValue + "; max-age=99999999;";
        document.getElementById("mainmenu").style.backgroundColor = colorValue;
    } else {
        alert("请输入颜色");
    }
}
function changeOuterColor() {
    var colorValue = document.getElementById("outerColor").value;
    if (colorValue) {
        document.cookie = "outerColor=" + colorValue + "; max-age=999999999;";
        document.getElementById("outer").style.backgroundColor = colorValue;
    } else {
        alert("请输入颜色");
    }
}