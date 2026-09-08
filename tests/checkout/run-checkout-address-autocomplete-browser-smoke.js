// Optional browser regression: provide JQUERY_PATH and Playwright via the local toolchain.
const { chromium } = require('playwright');
const assert = require('assert');
const path = require('path');
const os = require('os');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const width of [1200, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 800 } });
      await page.setContent(`<style>body{font:16px Arial;margin:20px}form{max-width:600px}input{box-sizing:border-box;width:100%;padding:12px}label{display:block;margin:12px 0} .woocommerce-input-wrapper{display:block}</style>
        <form><label>Адрес<span class="woocommerce-input-wrapper"><input id="billing_address_1" name="billing_address_1"></span></label><label>Телефон<input id="billing_phone"></label>
        <input type="hidden" id="billing_country" value="RU"><input type="hidden" id="billing_city" value="Москва"><input type="hidden" id="billing_state" value="Москва"><input type="hidden" id="billing_postcode" value="123456">
        <input type="hidden" name="wdc_platform_location_id" value="1"><input type="hidden" name="wdc_platform_location_fias_id" value="city-fias"><input type="hidden" name="wdc_platform_location_selected_source" value="database"></form>`);
      await page.addScriptTag({ path: process.env.JQUERY_PATH });
      await page.evaluate(() => {
        window.wdcPlatformAddressSuggestions = { enabled:true, min_chars:3 };
        window.requests=[]; window.updates=0;
        jQuery(document.body).on('update_checkout',()=>window.updates++);
        jQuery.post=(url,data)=>{ const d=jQuery.Deferred(); const r=d.promise(); r.abort=()=>d.reject({},'abort'); window.requests.push({ data, d }); return r; };
      });
      await page.addStyleTag({ path:path.join(__dirname,'../../assets/frontend/checkout-address-suggestions.css') });
      await page.addScriptTag({ path:path.join(__dirname,'../../assets/frontend/checkout-address-suggestions.js') });
      const input=page.locator('#billing_address_1');
      await input.fill('Ленина');
      await page.waitForFunction(()=>window.requests.length===1);
      await page.evaluate(()=>window.requests[0].d.resolve({success:true,items:[{input_value:'ул Ленина',display_label:'ул Ленина',secondary_label:'г Москва',level:'street',is_final:false,data:{}}]}));
      await page.locator('.wdc-address-autocomplete-option').click();
      assert.equal(await input.inputValue(),'ул Ленина');
      assert(await input.evaluate(n=>n===document.activeElement));
      await input.fill('ул Ленина 10');
      await page.waitForFunction(()=>window.requests.length===2);
      await page.evaluate(()=>window.requests[1].d.resolve({success:true,items:[{input_value:'ул Ленина, д 10',display_label:'ул Ленина, д 10',secondary_label:'г Москва',level:'house',is_final:true,selection_token:'opaque',data:{house:'10'}}]}));
      const fieldBox=await input.boundingBox(), listBox=await page.locator('.wdc-address-autocomplete').boundingBox();
      assert(Math.abs(fieldBox.width-listBox.width)<2 && listBox.x>=0 && listBox.x+listBox.width<=width);
      await page.screenshot({path:path.join(os.tmpdir(),`wdc-address-inline-${width}.png`)});
      await input.press('ArrowDown'); await input.press('Enter');
      assert.equal(await input.inputValue(),'ул Ленина, д 10, ');
      await page.evaluate(()=>window.requests[2].d.resolve({success:true,trusted:true}));
      assert.equal(await page.evaluate(()=>window.updates),1);
      await input.pressSequentially('кв 25');
      await page.waitForTimeout(400);
      assert.equal(await page.evaluate(()=>window.requests.length),3);
      await input.press('Tab');
      assert.equal(await page.locator('.wdc-address-autocomplete.is-open').count(),0);
      assert.equal(await page.locator('#billing_postcode').inputValue(),'123456');
      await input.fill('ул Ленина, д 1');
      await page.waitForFunction(()=>window.requests.length===4);
      await page.evaluate(()=>document.body.dispatchEvent(new CustomEvent('wdc:location-cleared')));
      await page.locator('#billing_phone').click();
      await page.evaluate(()=>{ window.requests[3].d.resolve({success:true,items:[]}); });
      assert.equal(await page.locator('.wdc-address-autocomplete.is-open').count(),0);
      await page.close();
    }
    console.log('Address autocomplete browser smoke passed: desktop/mobile, focus, keyboard, free tail, geography.');
  } finally { await browser.close(); }
})().catch(error=>{ console.error(error); process.exitCode=1; });
