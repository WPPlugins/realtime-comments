/*jslint browser: true, plusplus: true, sloppy: true */
/*global $DGD */
/*global jQuery */
/*global tb_show */
/*global tb_remove */
/*global console */
/*global pagenow */


if (typeof $EE !== 'object') {
    var $EE = { 'debug': true };
}


$EE.showTab = function (elem, tab) {
    jQuery(elem).parent('ul').next('.ee_tab_container').find('.ee_tab_content').addClass('hide');
    jQuery(elem).parent('ul').next('.ee_tab_container').find('.' + tab).removeClass('hide');
    jQuery(elem).parent('ul').find('li').removeClass('selected');
    jQuery(elem).addClass('selected');
};
