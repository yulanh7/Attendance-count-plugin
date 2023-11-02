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


  // Initialize the datepicker
  $('#last_date_filter').datepicker({
    dateFormat: 'dd/mm/yy', // Set the date format
    changeYear: true,
    changeMonth: true,
    showButtonPanel: true,
    yearRange: 'c-100:c+0', // Allow selection of the past 100 years to the current year
  });

  $(document).on('click','#filter-button', function (e) {
    e.preventDefault();
    console.log('aaaaa')
    const lastDate = $('#last_date_filter').val();
    const lastName = $('#last_name_filter').val();
    const firstName = $('#first_name_filter').val();
    const email = $('#email_filter').val();
    const isNew = $('#is_new_filter').is(':checked'); // Get the checkbox state
    const tableName = "#filter-table-response";
    function bindToggleRowEvent() {
      $('tbody').on('click', '.toggle-row', function() {
        $(this).closest('tr').toggleClass('is-expanded');
      });
    }
    

    $.ajax({
      url: esAjax.ajaxurl,
      type: 'POST',
      data: {
        action: 'es_filter_attendance',
        last_date: lastDate,
        last_name: lastName,
        first_name: firstName,
        email: email,
        is_new: isNew, // Send the isNew value

      },
      success: function (response) {
        $(tableName).html(response.data.table_html);
        bindToggleRowEvent();
      },
      error: function() {
        alert('An error occurred.');
    },
    });
  });
});

