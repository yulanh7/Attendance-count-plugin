jQuery(document).ready(function ($) {

  function updateFormFields() {
    let storedData = {};
    if (supportsLocalStorage()) {
      try {
        storedData = JSON.parse(localStorage.getItem('es_attendance_form_data')) || {};
      } catch (e) {
        storedData = {};
      }
    }
    $("input[name=es_first_name]").val(storedData.es_first_name || '');
    $("input[name=es_last_name]").val(storedData.es_last_name || '');
    $("input[name=es_email]").val(storedData.es_email || '');
    $("select[name=es_phone_country_code]").val(storedData.es_phone_country_code || '+61');
    $("input[name=es_phone_number]").val(storedData.es_phone_number || '');
    $("select[name=es_fellowship]").val(storedData.es_fellowship || '');
  }

  function supportsLocalStorage() {
    try {
      const test = 'test';
      localStorage.setItem(test, test);
      localStorage.removeItem(test);
      return true;
    } catch (e) {
      return false;
    }
  }

  updateFormFields();

  function isLocalEnvironment() {
    return window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  }

  // ===== 表单提交（带 nonce，防重复提交）=====
  $("form#es_attendance_form").on("submit", function (e) {
    e.preventDefault();

    const $form = $(this);
    const $submit = $form.find('input[type=submit], button[type=submit]');
    $submit.prop('disabled', true);

    const formData = {
      es_first_name: $("input[name=es_first_name]").val(),
      es_last_name: $("input[name=es_last_name]").val(),
      es_email: $("input[name=es_email]").val(),
      es_phone_country_code: $("select[name=es_phone_country_code]").val(),
      es_phone_number: $("input[name=es_phone_number]").val(),
      es_fellowship: $("select[name=es_fellowship]").val(),
    };

    if (supportsLocalStorage()) {
      localStorage.setItem('es_attendance_form_data', JSON.stringify(formData));
    }

    $.ajax({
      url: esAjax.ajaxurl, // 统一使用 esAjax.ajaxurl
      type: "POST",
      data: $.extend({
        action: "es_handle_attendance",
        nonce: esAjax.nonce // 👈 带上 nonce
      }, formData),
      success: function (response) {
        $(".es-message").remove();
        const msg = response && response.data && response.data.message ? response.data.message : (response?.message || '提交完成');
        displayMessage(msg, response && response.success ? 'green' : 'red');
        alert(msg);
      },
      error: function (xhr) {
        console.error('Error: ' + (xhr.responseText || ''));
        let msg = 'An error occurred. Please try again.';
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        displayMessage(msg, 'red');
        alert('网络错误，请稍后再试。');
      },
      complete: function () {
        $submit.prop('disabled', false);
      }
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

  $("form#es_attendance_form input").on("focus", function () {
    $(".es-message").remove();
  });

  // datepicker（若页面真的有该 ID）
  $("#last_date_filter").datepicker({
    dateFormat: "dd/mm/yy",
    changeYear: true,
    changeMonth: true,
    showButtonPanel: true,
    yearRange: "c-100:c+0",
  });

  function bindPaginationEvent() {
    $(document).on('click', '.pagination-links a', function (e) {
      e.preventDefault();
      var href = $(this).attr('href') || '';
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
    const data = {
      action: exportCsv ? "es_export_attendance_csv" : "es_filter_attendance",
      nonce: esAjax.nonce, // 👈 带上 nonce
      start_date_filter: $("#start_date_filter").val(),
      end_date_filter: $("#end_date_filter").val(),
      last_name: $("#last_name_filter").val(),
      first_name: $("#first_name_filter").val(),
      email: $("#email_filter").val(),
      phone: $("#phone_filter").val(),
      fellowship: $("#es_fellowship_filter").val(),
      is_new: $("#is_new_filter").is(":checked"),
      percentage_filter: $("#percentage_filter").is(":checked"),
      paged: page,
      is_member: $("#es_member_filter").val(),
    };

    if (exportCsv) {
      // 导出 CSV：后端应设置下载头，这里也可以直接下载 Blob
      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: data,
        success: function (response, status, xhr) {
          // 如果后端返回纯文本 CSV
          const blob = new Blob([response], { type: 'text/csv;charset=utf-8;' });
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
          document.body.removeChild(a);
          URL.revokeObjectURL(downloadUrl);
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
    fetchFilteredResults(1);
  });

  $(document).on("click", "#export-csv-button", function (e) {
    e.preventDefault();
    fetchFilteredResults(1, true);
  });

  // 批量“会员/非会员”更新（带 nonce）
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
            nonce: esAjax.nonce, // 👈 带上 nonce
            ids: selectedIDs,
            member_action: action
          },
          success: function (response) {
            const msg = response && response.data && response.data.message ? response.data.message : 'Updated.';
            alert(msg);
            const filter_params = JSON.parse(localStorage.getItem('filter_params') || '{}');
            handle_filter_data(filter_params);
          },
          error: function (xhr) {
            alert('Failed to update member status.');
          }
        });
      }
    }
  });

  function handle_filter_data(data) {
    const tableName = "#filter-table-response";
    // 强制动作为筛选
    data.action = 'es_filter_attendance';
    // 保证 nonce 存在
    if (!data.nonce) data.nonce = esAjax.nonce;

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
        if (response && response.success && response.data && response.data.table_html) {
          $(tableName).html(response.data.table_html);
        } else {
          $(tableName).html('<div class="notice notice-error">Failed to load data.</div>');
        }
        $('#loader-box').hide();
        bindToggleRowEvent();
        bindPaginationEvent();
        try {
          localStorage.setItem('filter_params', JSON.stringify(data));
        } catch (e) { }
      },
      error: function () {
        $('#loader-box').hide();
        alert("An error occurred.");
      },
    });
  }

  // 详情弹窗（统一用 esAjax.ajaxurl + nonce）
  $(document).on("click", ".view-attendance-button", function (e) {
    const filter_params = JSON.parse(localStorage.getItem('filter_params') || '{}');
    var attendanceId = $(this).data('attendance-id');
    $.ajax({
      url: esAjax.ajaxurl,
      type: 'POST',
      data: {
        action: 'get_attendance_info',
        nonce: esAjax.nonce, // 👈 带上 nonce
        attendance_id: attendanceId,
        start_date_filter: filter_params.start_date_filter,
        end_date_filter: filter_params.end_date_filter
      },
      success: function (response) {
        $('#attendance-info-modal-content').html(response);
        document.getElementById("attendance-info-modal").style.display = "block";
      },
      error: function (xhr, status, error) {
        console.error(error);
        alert('Failed to load attendance detail.');
      }
    });
  });

  $(document).on("click", "#attendance-info-modal .close", function (e) {
    document.getElementById("attendance-info-modal").style.display = "none";
  });

});
