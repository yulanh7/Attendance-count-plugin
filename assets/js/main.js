/* =========================================================
 * Attendance Plugin - main.js (single-file, structured)
 * - 统一 AJAX（自动带 nonce）
 * - 前台表单：本地存储回填 + 提交
 * - 后台：筛选/分页/导出/批量更新
 * - 详情弹窗：View Attendance
 * - Loading/消息提示/日期控件
 * =======================================================*/
jQuery(function ($) {
  /* ============================
   * 0) 命名空间 & 常量 / 小工具
   * ============================ */
  const AP = (window.AP = window.AP || {});

  const S = {
    tableBox: "#filter-table-response",
    loaderBox: "#loader-box",
    form: "#es_attendance_form",
    modal: "#attendance-info-modal",
    modalContent: "#attendance-info-modal-content",
  };

  const storage = {
    get(k, d = null) {
      try {
        const raw = localStorage.getItem(k);
        return raw ? JSON.parse(raw) : d;
      } catch (e) {
        return d;
      }
    },
    set(k, v) {
      try {
        localStorage.setItem(k, JSON.stringify(v));
      } catch (e) { }
    },
    del(k) {
      try {
        localStorage.removeItem(k);
      } catch (e) { }
    },
  };

  const api = {
    post(action, payload = {}) {
      // JSON 响应型（wp_send_json_*）
      return $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        dataType: "json",
        data: { action, nonce: esAjax.nonce, ...payload },
      }).fail((xhr) => {
        const msg =
          (xhr.responseJSON &&
            xhr.responseJSON.data &&
            xhr.responseJSON.data.message) ||
          xhr.responseText ||
          "Network error";
        console.error("AJAX error:", msg);
      });
    },
    postRaw(action, payload = {}) {
      // 非 JSON 响应（CSV / HTML 片段）
      return $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: { action, nonce: esAjax.nonce, ...payload },
      }).fail((xhr) => {
        console.error("AJAX raw error:", xhr && xhr.responseText);
      });
    },
  };

  function showLoader(show) {
    const $b = $(S.loaderBox);
    if (!$b.length) return;
    show ? $b.show() : $b.hide();
  }

  function displayMessage($after, message, color) {
    $(".es-message").remove();
    $("<div>", {
      class: "es-message",
      text: message,
      css: {
        background: color,
        color: "#ffffff",
        padding: "8px",
        "margin-top": "10px",
        "border-radius": "5px",
      },
    }).insertAfter($after);
  }

  /* ============================
   * 1) 前台表单（本地存储 + 提交）
   * ============================ */
  function initForm() {
    const $form = $(S.form);
    if (!$form.length) return;

    // 回填
    const saved = storage.get("es_attendance_form_data", {});
    $form.find("input[name=es_first_name]").val(saved.es_first_name || "");
    $form.find("input[name=es_last_name]").val(saved.es_last_name || "");
    $form.find("input[name=es_email]").val(saved.es_email || "");
    $form
      .find("select[name=es_phone_country_code]")
      .val(saved.es_phone_country_code || "+61");
    $form.find("input[name=es_phone_number]").val(saved.es_phone_number || "");
    $form.find("select[name=es_fellowship]").val(saved.es_fellowship || "");

    // 提交
    $form.on("submit", function (e) {
      e.preventDefault();
      const formData = {
        es_first_name: $form.find("input[name=es_first_name]").val(),
        es_last_name: $form.find("input[name=es_last_name]").val(),
        es_email: $form.find("input[name=es_email]").val(),
        es_phone_country_code: $form
          .find("select[name=es_phone_country_code]")
          .val(),
        es_phone_number: $form.find("input[name=es_phone_number]").val(),
        es_fellowship: $form.find("select[name=es_fellowship]").val(),
      };

      storage.set("es_attendance_form_data", formData);

      api.post("es_handle_attendance", formData).done((resp) => {
        $(".es-message").remove();
        const ok = !!(resp && resp.success);
        const msg =
          (resp && resp.data && resp.data.message) ||
          (ok ? "Success" : "Error");
        displayMessage($form, msg, ok ? "green" : "red");
        alert(msg);
      });
    });

    // 输入时清提示
    $form.on("focus", "input,select", function () {
      $(".es-message").remove();
    });

    // 日期控件（如果页面上有）
    $("#last_date_filter").datepicker({
      dateFormat: "dd/mm/yy",
      changeYear: true,
      changeMonth: true,
      showButtonPanel: true,
      yearRange: "c-100:c+0",
    });
  }

  /* ============================
   * 2) 后台筛选/分页/导出/批量
   * ============================ */
  function getFilters() {
    return {
      is_member: $("#es_member_filter").val(),
      fellowship: $("#es_fellowship_filter").val(),
      start_date_filter: $("#start_date_filter").val(),
      end_date_filter: $("#end_date_filter").val(),
      last_name: $("#last_name_filter").val(),
      first_name: $("#first_name_filter").val(),
      email: $("#email_filter").val(),
      phone: $("#phone_filter").val(),
      is_new: $("#is_new_filter").is(":checked"),
      percentage_filter: $("#percentage_filter").is(":checked"),
    };
  }

  function bindToggleRowEvent() {
    // WP_List_Table 响应式内的小箭头展开
    $("tbody").off("click", ".toggle-row").on("click", ".toggle-row", function () {
      $(this).closest("tr").toggleClass("is-expanded");
    });
  }

  function bindPaginationEvent() {
    $(document)
      .off("click", ".pagination-links a")
      .on("click", ".pagination-links a", function (e) {
        e.preventDefault();
        const href = $(this).attr("href") || "";
        const match = href.match(/paged=(\d+)/);
        const page = match ? parseInt(match[1], 10) : 1;
        fetchFilteredResults(page);
      });
  }

  function renderTableHTML(html) {
    $(S.tableBox).html(html);
    bindToggleRowEvent();
    bindPaginationEvent();
  }

  function fetchFilteredResults(page = 1) {
    const params = { ...getFilters(), paged: page };
    showLoader(true);
    api.post("es_filter_attendance", params).done((resp) => {
      if (resp && resp.success) {
        renderTableHTML(resp.data.table_html);
        storage.set("filter_params", params);
      } else {
        alert("加载失败，请稍后再试。");
      }
    }).always(() => showLoader(false));
  }

  function exportCSV() {
    const params = getFilters();
    api.postRaw("es_export_attendance_csv", params).done((response) => {
      const blob = new Blob([response], { type: "text/csv" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      const d = new Date();
      const dd = String(d.getDate()).padStart(2, "0");
      const mm = String(d.getMonth() + 1).padStart(2, "0");
      const yy = d.getFullYear();
      a.href = url;
      a.download = `Attendance_Report_${dd}-${mm}-${yy}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    });
  }

  function bulkMemberUpdate(action) {
    if (!/make_member|make_non_member/.test(action)) return;
    const ids = $('input[name="bulk-select[]"]:checked')
      .map(function () {
        return this.value;
      })
      .get();
    if (!ids.length) return;
    api
      .post("handle_member_status_update", { ids, member_action: action })
      .done((resp) => {
        const msg =
          (resp && resp.data && resp.data.message) ||
          "Member status updated.";
        alert(msg);
        const params = storage.get("filter_params") || getFilters();
        fetchFilteredResults(params.paged || 1);
      });
  }

  function initAdmin() {
    if (!$(S.tableBox).length) return;

    // 绑定筛选按钮
    $(document).on("click", "#filter-button", function (e) {
      e.preventDefault();
      fetchFilteredResults(1);
    });

    // 绑定导出
    $(document).on("click", "#export-csv-button", function (e) {
      e.preventDefault();
      exportCSV();
    });

    // 绑定批量按钮（WP_List_Table 顶/底部）
    $(document).on("click", "#doaction, #doaction2", function (e) {
      e.preventDefault();
      const action = $(this).prev("select").val();
      bulkMemberUpdate(action);
    });

    // 首次：不自动拉取（你后台页面已初次渲染），如需自动更新可打开：
    // fetchFilteredResults(1);

    bindToggleRowEvent();
    bindPaginationEvent();
  }

  /* ============================
   * 3) 详情弹窗（View Attendance）
   * ============================ */
  function initModal() {
    const $modal = $(S.modal);
    if (!$modal.length) return;

    // 打开
    $(document).on("click", ".view-attendance-button", function () {
      const id = $(this).data("attendance-id");
      const params = storage.get("filter_params") || getFilters();
      api
        .postRaw("get_attendance_info", {
          attendance_id: id,
          start_date_filter: params.start_date_filter,
          end_date_filter: params.end_date_filter,
        })
        .done((html) => {
          $(S.modalContent).html(html);
          $modal.show();
        });
    });

    // 关闭
    $(document).on("click", "#attendance-info-modal .close", function () {
      $modal.hide();
    });
  }

  /* ============================
   * 4) 启动
   * ============================ */
  (function boot() {
    initForm();
    initAdmin();
    initModal();
  })();
});
