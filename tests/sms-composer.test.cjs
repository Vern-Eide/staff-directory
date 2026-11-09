// Run with jsdom and jquery available on NODE_PATH.
const {JSDOM} = require('jsdom');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
 const dom = new JSDOM(`<body><div class="acf-postbox"><div class="inside"><div class="acf-tab-wrap"><ul>${[1,2,3].map((n)=>`<li class="${n===1?'active':''}"><a class="acf-tab-button" href="#">${n}</a></li>`).join('')}</ul></div><div data-name="sms_msg_department"><div class="acf-input"><select multiple><option selected value="1">Sales</option></select></div></div><div data-name="sms_msg_location"><select multiple><option selected value="1">Location</option></select></div><div id="ve-sms-recipient-slot"></div><div data-name="sms_msg_text"><textarea></textarea></div><div data-name="scheduled_message"><div class="acf-input"></div></div></div></div><div id="ve-sms-recipients-box"><div class="inside"><button class="ve-sms-load-recipients" data-recipient-target="#ve-sms-recipients">Load</button><select multiple id="ve-sms-recipients" class="ve-sms-recipients"><option selected value="1">Alex | Sales</option></select><p class="description"></p></div></div><div id="submitdiv"><input id="publish"></div></body>`, {runScripts:'outside-only'});
 dom.window.eval(fs.readFileSync(require.resolve('jquery'),'utf8')); const $ = dom.window.jQuery; dom.window.veAjax = {ajaxurl:'/ajax', nonce:'test'};
 let config, reply; $.fn.select2 = function(c) {config=c; return this;};
 $.post = () => { const d=$.Deferred(); setTimeout(()=>d.resolve(reply),0); return d.promise(); };
 $('.acf-tab-button').on('click',function(e){ e.preventDefault(); $(this).parent().addClass('active').siblings().removeClass('active'); });
 dom.window.eval(fs.readFileSync(require('node:path').join(__dirname,'../assets/plugins/ve-staff/admin/js/ve-staff-sms-admin.js'),'utf8'));
 const tick = () => new Promise(r=>setTimeout(r,30)); await tick();
 assert.equal(config.templateSelection({text:'Alex | Sales'}),'Alex');
 assert.equal($('#ve-sms-recipient-slot #ve-sms-recipients').length,1);
 assert.equal($('[data-name="sms_msg_department"] .ve-sms-load-recipients').length,1);
 $('.ve-sms-next').trigger('click'); await tick(); assert.equal($('.ve-sms-step-label').text(),'Step 2 of 3');
 $('.ve-sms-next').trigger('click'); await tick(); assert.equal($('#submitdiv').css('display'),'block');
 reply={success:true,data:[{id:2,text:'Blake | Service'}]}; $('.ve-sms-load-recipients').trigger('click'); await tick(); assert.deepEqual(Array.from($('#ve-sms-recipients').val()),['2']);
 reply={success:true,data:{recipients:[{id:2,text:'Blake | Service'},{id:3,text:'Casey | Sales'}],duplicates:1,skipped:2}};
 $('#ve-sms-paste').val('6055550100, casey@example.com'); $('.ve-sms-import-button').trigger('click'); await tick();
 assert.deepEqual(Array.from($('#ve-sms-recipients').val()),['2','3']); assert.match($('.ve-sms-import-status').text(),/1 added; 2 duplicates skipped; 2 unmatched/);
 reply={success:false,data:{message:'Rejected'}}; $('.ve-sms-load-recipients').trigger('click'); await tick(); assert.deepEqual(Array.from($('#ve-sms-recipients').val()),['2','3']);
 $('[data-name="sms_msg_text"] textarea').val('<img src=x onerror=alert(1)>').trigger('input'); assert.equal($('.ve-sms-preview img').length,0);
 console.log('SMS composer DOM checks passed'); dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
