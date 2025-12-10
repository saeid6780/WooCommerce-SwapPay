const swap_pay_settings = window.wc.wcSettings.getSetting("WC_Swap_Pay_data", {});
const swap_pay_label =
  window.wp.htmlEntities.decodeEntities(swap_pay_settings.title) ||
  "سواپ‌ولت";

const Swap_Pay_icon = Object(window.wp.element.createElement)("img", {
  src: swap_pay_settings.icon,
  alt: window.wp.htmlEntities.decodeEntities(swap_pay_settings.title),
  style: { marginLeft: "10px", display: "inline-block" },
});

const swap_pay_label_with_icon = window.wp.element.createElement("span", null, [
  Swap_Pay_icon,
  swap_pay_label,
]);

const swap_pay_Content = () => {
  return window.wp.htmlEntities.decodeEntities(
    swap_pay_settings.description ||
      "پرداخت امن و سریع با استفاده از سواپ‌ولت"
  );
};

const Swap_Pay_Block_Gateway = {
  name: "WC_Swap_Pay",
  label: swap_pay_label_with_icon,
  content: Object(window.wp.element.createElement)(swap_pay_Content, null),
  edit: Object(window.wp.element.createElement)(swap_pay_Content, null),
  canMakePayment: () => true,
  ariaLabel: swap_pay_label,
  supports: {
    features: swap_pay_settings.supports,
  },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Swap_Pay_Block_Gateway);
