
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

// 禁止部分母菜单的跳转
var mainMenu = document.getElementById("mainmenu"); // 获取 ul 元素

// 遍历 li 标签
for (var i = 0; i < mainMenu.children.length; i++) {
    var li = mainMenu.children[i]; // 获取当前的 li 元素
    var a = li.querySelector("a"); // 获取 li 内部的 a 元素

    if (a.href.includes("notouch")) { // 检查 a 标签的 href 是否包含 "notouch"
        a.removeAttribute("href"); // 移除 href 属性，禁止跳转
    }
}