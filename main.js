jQuery(document).ready(function ($) {
  $("form#es_attendance_form").submit(function (e) {
    e.preventDefault();
    const es_first_name = $("input[name=es_first_name]").val();
    const es_last_name = $("input[name=es_last_name]").val();
    const es_email = $("input[name=es_email]").val();
    const es_phone = $("input[name=es_phone]").val();

    $.post(
      esAjax.ajaxurl,
      {
        action: "es_handle_attendance",
        es_first_name,
        es_last_name,
        es_email,
        es_phone,
      },
      function (response) {
        // Remove existing messages
        $(".es-message").remove();

        // Append new message
        if (response.success) {
          $("form#es_attendance_form").after(
            '<div class="es-message" style="color:green;">' +
              response.data.message +
              "</div>"
          );
        } else {
          $("form#es_attendance_form").after(
            '<div class="es-message" style="color:red;">' +
              response.data.message +
              "</div>"
          );
        }
        $("form#es_attendance_form")[0].reset();
      }
    );
  });

  $("form#es_attendance_form input").focus(function () {
    // Remove the message div when input is focused
    $(".es-message").remove();
  });
});
