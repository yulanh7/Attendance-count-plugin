jQuery(document).ready(function ($) {

  function updateFormFields() {
    const storedData = JSON.parse(localStorage.getItem('es_attendance_form_data')) || {};
    $("input[name=es_first_name]").val(storedData.es_first_name || '');
    $("input[name=es_last_name]").val(storedData.es_last_name || '');
    $("input[name=es_email]").val(storedData.es_email || '');
    $("input[name=es_phone]").val(storedData.es_phone || '');
    $("select[name=es_fellowship]").val(storedData.es_fellowship || '');
  }
  updateFormFields();


  $("form#es_attendance_form").submit(function (e) {
    e.preventDefault();
    const formData = {
      es_first_name: $("input[name=es_first_name]").val(),
      es_last_name: $("input[name=es_last_name]").val(),
      es_email: $("input[name=es_email]").val(),
      es_phone: $("input[name=es_phone]").val(),
      es_fellowship: $("select[name=es_fellowship]").val()
    };
    // Capture the reCAPTCHA response
    const recaptchaResponse = grecaptcha.getResponse();

    // Check if the response is empty (indicating the user didn't complete the reCAPTCHA)
    if (recaptchaResponse === "") {
      displayMessage('Please complete the reCAPTCHA.', 'red');
      return;
    }
    localStorage.setItem('es_attendance_form_data', JSON.stringify(formData));

    $.ajax({
      url: esAjax.ajaxurl,
      type: "POST",
      data: $.extend({ action: "es_handle_attendance" }, formData),
      success: function (response) {
        // Handle success
        $(".es-message").remove();
        displayMessage(response.data.message, response.success ? 'green' : 'red');
      },
      error: function (xhr, textStatus, errorThrown) {
        console.error('Error: ' + xhr.responseText);
        displayMessage('An error occurred. Please try again.', 'red');

      },

    });
    function displayMessage(message, color) {
      $(".es-message").remove();
      $("<div>", {
        "class": "es-message",
        "text": message,
        "css": {
          "color": color
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
    const fellowship = $("#es_fellowship_filter").val(); // Get the selected fellowship
    const start_date_filter = $("#start_date_filter").val();
    const end_date_filter = $("#end_date_filter").val();
    const lastName = $("#last_name_filter").val();
    const firstName = $("#first_name_filter").val();
    const email = $("#email_filter").val();
    const isNew = $("#is_new_filter").is(":checked"); // Get the checkbox state
    const percentageFilter = $("#percentage_filter").is(":checked"); // Get the checkbox state
    const tableName = "#filter-table-response";
    const data = {
      action: exportCsv ? "es_export_attendance_csv" : "es_filter_attendance",
      start_date_filter,
      end_date_filter,
      last_name: lastName,
      first_name: firstName,
      email: email,
      fellowship: fellowship,
      is_new: isNew,
      percentage_filter: percentageFilter,
      paged: page,
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

      },
      error: function () {
        $('#loader-box').hide();

        alert("An error occurred.");
      },
    });
  }

  $(document).on("click", "#filter-button", function (e) {
    e.preventDefault();
    fetchFilteredResults(1); // Fetch results for the first page
  });


  $(document).on("click", "#export-csv-button", function (e) {
    e.preventDefault();
    fetchFilteredResults(1, true); // Fetch results for the first page
  });

});
