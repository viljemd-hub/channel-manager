document.addEventListener("DOMContentLoaded", function () {

  CMGuide.start([
    {
      el: "#mr-filter-unit",
      title: "Units filter",
      text: "Here you can filter reservations by unit. Leave empty to see all."
    },
    {
      el: "#mr-filter-status",
      title: "Status filter",
      text: "Filter by confirmed, cancelled or soft-hold reservations."
    },
    {
      el: "#manage-reservations",
      title: "Reservations list",
      text: "This is your main list. Click any reservation to see details."
    },
    {
      el: "#mr-detail",
      title: "Reservation detail",
      text: "Here you can see guest data, stay info and status."
    },
    {
      el: ".mr-btn-cancel",
      title: "Cancel reservation",
      text: "Cancel will free the dates and update calendar immediately."
    },
    {
      el: ".mr-btn-resend",
      title: "Re-send accept",
      text: "For soft-hold reservations you can re-send the confirmation link."
    }
  ]);

});