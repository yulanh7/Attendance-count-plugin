jQuery(document).ready(function ($) {

  function updateFormFields() {
    const storedData = JSON.parse(localStorage.getItem('es_attendance_form_data')) || {};
    $("input[name=es_first_name]").val(storedData.es_first_name || '');
    $("input[name=es_last_name]").val(storedData.es_last_name || '');
    $("input[name=es_email]").val(storedData.es_email || '');
    $("select[name=es_phone_country_code]").val(storedData.es_phone_country_code || '+61');
    $("input[name=es_phone_number]").val(storedData.es_phone_number || '');
    $("select[name=es_fellowship]").val(storedData.es_fellowship || '');
  }
  updateFormFields();

  function isLocalEnvironment() {
    return window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  }

  $("form#es_attendance_form").submit(function (e) {
    e.preventDefault();
    const formData = {
      es_first_name: $("input[name=es_first_name]").val(),
      es_last_name: $("input[name=es_last_name]").val(),
      es_email: $("input[name=es_email]").val(),
      es_phone_country_code: $("select[name=es_phone_country_code]").val(),
      es_phone_number: $("input[name=es_phone_number]").val(),
      es_fellowship: $("select[name=es_fellowship]").val()
    };

    // Capture the reCAPTCHA response
    // Check if the response is empty (indicating the user didn't complete the reCAPTCHA)
    if (!isLocalEnvironment()) {
      const recaptchaResponse = grecaptcha.getResponse();
      if (recaptchaResponse === "") {
        displayMessage('Please complete the reCAPTCHA.', 'red');
        return;
      }
    } else {
      console.log("Skipping reCAPTCHA in local environment");
    }

    localStorage.setItem('es_attendance_form_data', JSON.stringify(formData));

    $.ajax({
      url: esAjax.ajaxurl,
      type: "POST",
      data: $.extend({ action: "es_handle_attendance" }, formData),
      success: function (response) {
        // Handle success
        $(".es-message").remove();
        // displayMessage(response.data.message, response.success ? 'green' : 'red');
        alert(response.data.message);
      },
      error: function (xhr, textStatus, errorThrown) {
        console.error('Error: ' + xhr.responseText);
        // displayMessage('An error occurred. Please try again.', 'red');
        alert('网络错误，请稍后再试。');
      },

    });
    function displayMessage(message, color) {
      $(".es-message").remove();
      $("<div>", {
        "class": "es-message",
        "text": message,
        "css": {
          "background": color,
          "color": "#ffffff",
          "padding": "8px",
          "margin-top": "10px",
          "border-radius": "5px"
        }
      }).insertAfter("form#es_attendance_form");
    }
  });

  $("form#es_attendance_form input").focus(function () {
    // Remove the message div when input is focused
    $(".es-message").remove();
  });

  // Initialize the datepicker
  $("#last_date_filter").datepicker({
    dateFormat: "dd/mm/yy", // Set the date format
    changeYear: true,
    changeMonth: true,
    showButtonPanel: true,
    yearRange: "c-100:c+0", // Allow selection of the past 100 years to the current year
  });

  function bindPaginationEvent() {
    $(document).on('click', '.pagination-links a', function (e) {
      e.preventDefault();
      // This will extract the page number from the query string in the href attribute
      var href = $(this).attr('href');
      var match = href.match(/paged=(\d+)/);
      var page = match ? parseInt(match[1], 10) : false;
      if (page) {
        fetchFilteredResults(page);
      } else {
        console.error('Page number is undefined.');
      }
    });
  }


  function fetchFilteredResults(page, exportCsv = false) {
    const is_member = $("#es_member_filter").val();
    const fellowship = $("#es_fellowship_filter").val();
    const start_date_filter = $("#start_date_filter").val();
    const end_date_filter = $("#end_date_filter").val();
    const lastName = $("#last_name_filter").val();
    const firstName = $("#first_name_filter").val();
    const email = $("#email_filter").val();
    const phone = $("#phone_filter").val();
    const isNew = $("#is_new_filter").is(":checked");
    const percentageFilter = $("#percentage_filter").is(":checked");
    const tableName = "#filter-table-response";
    const data = {
      action: exportCsv ? "es_export_attendance_csv" : "es_filter_attendance",
      start_date_filter,
      end_date_filter,
      last_name: lastName,
      first_name: firstName,
      email: email,
      phone: phone,
      fellowship: fellowship,
      is_new: isNew,
      percentage_filter: percentageFilter,
      paged: page,
      is_member: is_member,
    };

    if (exportCsv) {
      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: data,
        success: function (response) {
          const blob = new Blob([response], { type: 'text/csv' });
          const downloadUrl = URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.href = downloadUrl;
          const currentDate = new Date();
          const day = ("0" + currentDate.getDate()).slice(-2);
          const month = ("0" + (currentDate.getMonth() + 1)).slice(-2);
          const year = currentDate.getFullYear();
          const formattedDate = `${day}-${month}-${year}`;
          a.download = `Attendance_Report_${formattedDate}.csv`;
          document.body.appendChild(a);
          a.click();
        },
        error: function () {
          alert("An error occurred.");
        }
      });
    }

    handle_filter_data(data);
  }

  $(document).on("click", "#filter-button", function (e) {
    e.preventDefault();
    fetchFilteredResults(1); // Fetch results for the first page
  });


  $(document).on("click", "#export-csv-button", function (e) {
    e.preventDefault();
    fetchFilteredResults(1, true); // Fetch results for the first page
  });

  $(document).on("click", "#doaction, #doaction2", function (e) {
    e.preventDefault();
    var action = $(this).prev('select').val();
    if (action === 'make_member' || action === 'make_non_member') {

      var selectedIDs = $('input[name="bulk-select[]"]:checked').map(function () {
        return this.value;
      }).get();
      if (selectedIDs.length > 0) {
        $.ajax({
          url: esAjax.ajaxurl,
          type: "POST",
          data: {
            action: 'handle_member_status_update',
            ids: selectedIDs,
            member_action: action
          },
          success: function (response) {
            alert(response.data.message); // Alert the response
            const filter_params = JSON.parse(localStorage.getItem('filter_params')) || {};
            handle_filter_data(filter_params)
          }
        });
      }
    }
  });


  function handle_filter_data(data) {
    const tableName = "#filter-table-response";
    data.action = 'es_filter_attendance';
    function bindToggleRowEvent() {
      $("tbody").on("click", ".toggle-row", function () {
        $(this).closest("tr").toggleClass("is-expanded");
      });
    }
    $('#loader-box').show();
    $.ajax({
      url: esAjax.ajaxurl,
      type: "POST",
      data: data,
      success: function (response) {
        $(tableName).html(response.data.table_html);
        $('#loader-box').hide();
        bindToggleRowEvent();
        bindPaginationEvent();
        localStorage.setItem('filter_params', JSON.stringify(data));

      },
      error: function () {
        $('#loader-box').hide();

        alert("An error occurred.");
      },
    });
  }

  $(document).on("click", ".view-attendance-button", function (e) {

    var attendanceId = $(this).data('attendance-id');
    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'get_attendance_info',
        attendance_id: attendanceId
      },
      success: function (response) {

        console.log(response);
        $('#attendance-info-modal-content').html(response)
        // This function will execute after html() finishes setting the content
        document.getElementById("attendance-info-modal").style.display = "block";

      },
      error: function (xhr, status, error) {
        console.error(error);
      }
    });
  });

  $(document).on("click", "#attendance-info-modal .close", function (e) {
    document.getElementById("attendance-info-modal").style.display = "none";

  })

});
