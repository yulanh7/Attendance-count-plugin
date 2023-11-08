jQuery(document).ready(function ($) {
  // Check if values exist in local storage
  const storedFirstName = localStorage.getItem("es_first_name");
  const storedLastName = localStorage.getItem("es_last_name");
  const storedEmail = localStorage.getItem("es_email");
  const storedPhone = localStorage.getItem("es_phone");
  const storedCongregation = localStorage.getItem("es_congregation"); // Added for dropdown

  // Set form field values if they exist in local storage
  if (storedFirstName) {
    $("input[name=es_first_name]").val(storedFirstName);
  }
  if (storedLastName) {
    $("input[name=es_last_name]").val(storedLastName);
  }
  if (storedEmail) {
    $("input[name=es_email]").val(storedEmail);
  }
  if (storedPhone) {
    $("input[name=es_phone]").val(storedPhone);
  }
  if (storedCongregation) {
    // Set the selected option for the dropdown
    $("select[name=es_congregation]").val(storedCongregation);
  }
  $("form#es_attendance_form").submit(function (e) {
    e.preventDefault();
    const es_first_name = $("input[name=es_first_name]").val();
    const es_last_name = $("input[name=es_last_name]").val();
    const es_email = $("input[name=es_email]").val();
    const es_phone = $("input[name=es_phone]").val();
    const es_congregation = $("select[name=es_congregation]").val(); // Get the selected dropdown value
// Capture the reCAPTCHA response
var recaptchaResponse = grecaptcha.getResponse();

// Check if the response is empty (indicating the user didn't complete the reCAPTCHA)
if (recaptchaResponse === "") {
  $("form#es_attendance_form").after(
    '<div class="es-message" style="color:red;">Please complete the reCAPTCHA.</div>' 
  );
  return;
}
    // Store values in local storage
    localStorage.setItem("es_first_name", es_first_name);
    localStorage.setItem("es_last_name", es_last_name);
    localStorage.setItem("es_email", es_email);
    localStorage.setItem("es_phone", es_phone);
    localStorage.setItem("es_congregation", es_congregation); // Store the selected dropdown value
    $.ajax({
      url: esAjax.ajaxurl,
      type: "POST",
      data: {
        action: "es_handle_attendance",
        es_first_name,
        es_last_name,
        es_email,
        es_phone,
        es_congregation,
      },
      success: function (response) {
        // Handle success
        $(".es-message").remove();

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
      },
      error: function (xhr, textStatus, errorThrown) {
        console.error('Error: ' + xhr.responseText);
      },
      
    });
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
    $(document).on('click', '.pagination-links a', function(e) {
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
  

  function fetchFilteredResults(page) {
    const congregation = $("#es_congregation_filter").val(); // Get the selected congregation
    const start_date_filter = $("#start_date_filter").val();
    const end_date_filter = $("#end_date_filter").val();
    const lastName = $("#last_name_filter").val();
    const firstName = $("#first_name_filter").val();
    const email = $("#email_filter").val();
    const isNew = $("#is_new_filter").is(":checked"); // Get the checkbox state
    const percentageFilter = $("#percentage_filter").is(":checked"); // Get the checkbox state
    const tableName = "#filter-table-response";
    function bindToggleRowEvent() {
      $("tbody").on("click", ".toggle-row", function () {
        $(this).closest("tr").toggleClass("is-expanded");
      });
    }
    
    $('#loader-box').show();

    $.ajax({
      url: esAjax.ajaxurl,
      type: "POST",
      data: {
        action: "es_filter_attendance",
        start_date_filter,
        end_date_filter,
        last_name: lastName,
        first_name: firstName,
        email: email,
        congregation: congregation,
        is_new: isNew,
        percentage_filter: percentageFilter,
        paged: page,
      },
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
});
