/**
 * CM Free / CM Plus – Integrations page guide
 */

document.addEventListener("DOMContentLoaded", function () {
  if (!window.CMGuide) return;

  CMGuide.start([
    {
      el: "#unitSelect",
      title: "Select unit",
      text: "All integrations are configured per unit. Start by selecting the apartment you want to connect."
    },
    {
      el: "#card-units",
      title: "Units",
      text: "Here you manage units and the system base URL used for generated links."
    },
    {
      el: "#cmBaseUrl",
      title: "Base URL",
      text: "This public URL is used to generate ICS links and other external system links."
    },
    {
      el: "#card-ics",
      title: "ICS Export",
      text: "These links allow external platforms to read your CM availability."
    },
    {
      el: "#card-channels",
      title: "Channels",
      text: "Here you connect external calendars. Imported bookings are merged into your availability."
    },
    {
      el: "#card-autopilot",
      title: "Autopilot",
      text: "Autopilot is a Plus feature that can automatically confirm safe inquiries when all rules pass."
    }
  ]);
});