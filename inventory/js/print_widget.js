// public/js/print_widget.js
(function () {
  function $(id) { return document.getElementById(id); }

  function openWidgetFromButton(btn) {
    const widget = $("printWidget");
    if (!widget) return;

    $("w_sku").textContent  = btn.dataset.sku || "";
    $("w_prod").textContent = btn.dataset.desc || "";
    $("w_uom").textContent  = btn.dataset.uom || "EA";
    $("w_qty").value        = btn.dataset.qty || "1";

    // Store UNIT barcode on the modal (preferred source)
    widget.dataset.upc = btn.dataset.upc || "";

    widget.style.display = "flex";
  }

  function closeWidget() {
    const widget = $("printWidget");
    if (widget) widget.style.display = "none";
  }

  async function executeZebraPrint() {
    const widget = $("printWidget");
    if (!widget) return;

    const sku  = $("w_sku").textContent.trim();
    const prod = $("w_prod").textContent.trim();
    const qty  = $("w_qty").value;
    const uom  = $("w_uom").textContent.trim();

    // Always use UNIT barcode from product_uom_barcodes (passed via data-upc)
    let upc = String(widget.dataset.upc || "").replace(/\D+/g, "");
    if (!upc) {
      alert("No UNIT barcode found for this SKU.");
      return;
    }

    // UPC-A: use 11 digits in ^FD; if 12 provided, drop check digit
    if (upc.length === 12) upc = upc.slice(0, 11);
    upc = upc.padStart(11, "0").slice(-11);

    const zpl = `
^XA^CI28
^FO0,0^GB300,150,150^FS^FO20,50^A0N,40,40^FR^FDPRODUCT =^FS^FO320,50^A0N,40,40^FD${prod}^FS
^FO0,150^GB300,150,150^FS^FO60,200^A0N,40,40^FR^FDSKU # =^FS^FO320,200^A0N,40,40^FD${sku}^FS
^FO0,300^GB300,150,150^FS^FO40,350^A0N,40,40^FR^FDAMOUNT =^FS^FO500,340^A0N,80,80^FD${qty}^FS
^FO0,450^GB300,150,150^FS^FO90,500^A0N,40,40^FR^FDUOM =^FS^FO320,500^A0N,40,40^FD${uom}^FS
^FO0,0^GB800,600,4^FS^FO300,0^GB4,600,4^FS^FO0,150^GB800,4,4^FS^FO0,300^GB800,4,4^FS^FO0,450^GB800,4,4^FS

^BY2,3,80
^FO360,470^BUN,80,Y,N,Y
^FD${upc}^FS

^PQ1^XZ`;

    try {
      const response = await fetch("http://localhost:9100/write", { method: "POST", body: zpl });
      if (response.ok) alert("Label Sent!");
    } catch (e) {
      alert("Printer not found. Ensure Zebra Browser Print is running.");
    }

    closeWidget();
  }

  function bind() {
    // Event delegation: works for dynamic tables too
    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".print-widget-trigger");
      if (btn) openWidgetFromButton(btn);

      if (e.target.closest("[data-print-widget-cancel]")) closeWidget();
      if (e.target.closest("[data-print-widget-print]")) executeZebraPrint();
    });

    // Optional: click outside card to close
    document.addEventListener("click", (e) => {
      const widget = $("printWidget");
      if (!widget) return;
      if (widget.style.display !== "flex") return;
      if (e.target === widget) closeWidget();
    });

    // ESC to close
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeWidget();
    });
  }

  // Auto-bind on load
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }

  // Optional: expose API if you want to open programmatically
  window.PrintWidget = { openWidgetFromButton, closeWidget, executeZebraPrint };
})();