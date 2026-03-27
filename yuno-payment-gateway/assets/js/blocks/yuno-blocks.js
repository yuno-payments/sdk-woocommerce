/*!
 * Yuno Payment Gateway - WooCommerce Blocks Integration
 *
 * COMPILED FILE - Do not edit directly.
 * Source: src/blocks/yuno-blocks.js
 * Build:  npm run build (uses @wordpress/scripts)
 *
 * @see https://github.com/yuno-payments/sdk-woocommerce
 */(()=>{"use strict";const e=window.wc.wcBlocksRegistry,t=window.wp.htmlEntities,n=window.wc.wcSettings,a=window.wp.element,s=(0,n.getSetting)("yuno_data",{}),i=(0,t.decodeEntities)(s.title||"Yuno"),l=()=>{const e=s.cardIcons||[];return 0===e.length?null:(0,a.createElement)("div",{style:{display:"flex",gap:"8px",marginTop:"8px"}},...e.map(e=>(0,a.createElement)("img",{key:e.name,src:e.src,alt:e.name,style:{height:"24px",width:"auto"}})))};(0,e.registerPaymentMethod)({name:"yuno",label:(0,a.createElement)(()=>(0,a.createElement)("span",{style:{display:"flex",alignItems:"center",justifyContent:"space-between",width:"100%"}},i,s.icon?(0,a.createElement)("img",{src:s.icon,alt:"",style:{height:"20px",width:"auto"}}):null)),content:(0,a.createElement)(e=>{const{eventRegistration:n,emitResponse:i}=e,{onPaymentSetup:c}=n;return(0,a.useEffect)(()=>c(()=>({type:i.responseTypes.SUCCESS,meta:{paymentMethodData:{}}})),[c,i.responseTypes.SUCCESS]),(0,a.createElement)("div",null,(0,a.createElement)("p",null,(0,t.decodeEntities)(s.description||"")),(0,a.createElement)(l))}),edit:(0,a.createElement)(()=>(0,a.createElement)("div",null,(0,a.createElement)("p",null,(0,t.decodeEntities)(s.description||"")),(0,a.createElement)(l))),canMakePayment:()=>!0,ariaLabel:i,supports:{features:s.supports||["products"]}})})();