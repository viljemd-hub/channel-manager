/**
 * CM Free / CM Plus – Inquiries page guide
 * Lightweight page-specific onboarding.
 */

document.addEventListener("DOMContentLoaded", function () {
  if (!window.CMGuide) return;

  CMGuide.start([
    {
      el: "#inq-unit-select",
      title: "Inquiry units",
      text: "Filter inquiries by unit. Use All when you want to review everything in one place."
    },
    {
      el: "#inq-refresh-btn",
      title: "Refresh inquiries",
      text: "Reload the current inquiry list after new guest requests, accepts, rejects, or calendar changes."
    },
    {
      el: "#inqList",
      title: "Inquiry list",
      text: "New guest inquiries appear here. Click one inquiry to open the full details on the right."
    },
    {
      el: "#inqDetail",
      title: "Inquiry details",
      text: "This panel shows the guest, dates, nights, price data and actions for the selected inquiry."
    },
    {
      el: "#inqDetail",
      title: "Accept flow",
      text: "Accepting an inquiry creates a soft-hold and sends the guest a confirmation link."
    },
    {
      el: "#inqDetail",
      title: "Calendar connection",
      text: "Marked or accepted inquiries are reflected on the admin calendar, so you can visually track important dates."
    },
    {
      el: "#inqDetail",
      title: "Reject flow",
      text: "Rejecting an inquiry removes it from the active workflow and can notify the guest, depending on your settings."
    }
  ]);
});