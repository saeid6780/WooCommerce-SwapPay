const swap_pay_settings =
  window.wc.wcSettings.getSetting("SwapPay_WC_Gateway_data", {});

const decode = window.wp.htmlEntities.decodeEntities;
const lang = (swap_pay_settings.language || "fa").toLowerCase();
const show_icon =
  swap_pay_settings.show_icon !== false &&
  swap_pay_settings.show_icon !== "no";
const icon_size = Math.min(
  256,
  Math.max(8, parseInt(swap_pay_settings.icon_size, 10) || 40)
);

const fallbackTitle =
  (lang === "en"
    ? swap_pay_settings.fallbacks?.label_en
    : swap_pay_settings.fallbacks?.label_fa) ||
  swap_pay_settings.title ||
  "Swap Wallet";

const label_text = decode(swap_pay_settings.title || fallbackTitle);

const swap_pay_label = Object(window.wp.element.createElement)(
  "span",
  {style: {fontWeight: "bold", fontSize: "1.5rem"}},
  label_text
);

const hasIcon = show_icon && !!swap_pay_settings.icon;

const Swap_Pay_icon = hasIcon
  ? Object(window.wp.element.createElement)("img", {
      src: swap_pay_settings.icon,
      alt: label_text,
      style: {
        marginInlineStart: "10px",
        display: "inline-block",
        width: `${icon_size}px`,
        height: "auto",
        maxWidth: `${icon_size}px`,
        maxHeight: `${icon_size}px`,
        objectFit: "contain",
        borderRadius: "8px",
      },
    })
  : null;

const swap_pay_label_with_icon = hasIcon
  ? window.wp.element.createElement(
      "span",
      {
        style: {
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          width: "100%",
          gap: "10px",
        },
      },
      [swap_pay_label, Swap_Pay_icon]
    )
  : swap_pay_label;

const swap_pay_Content = () => {
  const fallbackDesc =
    (lang === "en"
      ? swap_pay_settings.fallbacks?.desc_en
      : swap_pay_settings.fallbacks?.desc_fa) ||
    "Pay securely with Swap Wallet";

  return decode(swap_pay_settings.description || fallbackDesc);
};

const Swap_Pay_Block_Gateway = {
  name: "SwapPay_WC_Gateway",
  label: swap_pay_label_with_icon,
  content: Object(window.wp.element.createElement)(swap_pay_Content, null),
  edit: Object(window.wp.element.createElement)(swap_pay_Content, null),
  canMakePayment: () => true,
  ariaLabel: label_text,
  supports: {
    features: swap_pay_settings.supports || ["products"],
  },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Swap_Pay_Block_Gateway);
