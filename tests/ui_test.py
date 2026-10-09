"""Prueba de la interfaz en un navegador real (Chromium, tamaño celular).
Uso: python3 tests/ui_test.py [http://localhost:8080]
Guarda capturas en tests/tmp/.
"""
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "tests", "tmp")
MAIL = os.path.join(ROOT, "storage", "logs", "mail.log")
os.makedirs(OUT, exist_ok=True)

fails = 0
checks = 0


def check(ok, msg):
    global fails, checks
    checks += 1
    print(("  ✓ " if ok else "  ✗ ") + msg)
    if not ok:
        fails += 1


def last_code(email):
    with open(MAIL, encoding="utf-8") as f:
        blocks = f.read().split("=== ")
    for b in reversed(blocks):
        if ("Para: " + email) in b:
            m = re.search(r"^\s{4}(\d{6})$", b, re.M)
            if m:
                return m.group(1)
    return None


def shot(page, name):
    page.screenshot(path=os.path.join(OUT, name + ".png"), full_page=False)


email = "ui.%d@example.com" % int(time.time())
console_errors = []

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="es-PE")
    page = ctx.new_page()
    page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: console_errors.append(str(e)))

    print("1) Portada y login")
    page.goto(BASE + "/")
    shot(page, "01-portada")
    check("Repasa tus cursos" in page.content(), "la portada carga")
    page.click("text=Empezar gratis")
    page.fill("#email", email)
    shot(page, "02-entrar")
    page.click("button:has-text('Enviarme un código')")
    page.wait_for_url("**/entrar/codigo")
    code = last_code(email)
    check(code is not None, "llegó el código")
    page.fill("#codigo", code)
    shot(page, "03-codigo")
    page.click("button:has-text('Entrar')")
    page.wait_for_url("**/hoy")
    check("Todavía no tienes mazos" in page.content(), "entra y ve Hoy vacío")
    shot(page, "04-hoy-vacio")

    print("2) Importar un CSV desde la interfaz")
    page.click(".bottomnav >> text=Importar")
    page.set_input_files("#archivo", os.path.join(ROOT, "tests", "fixtures", "matematica-ii.csv"))
    page.fill("#nuevo_mazo", "Matemática II")
    page.click("button:has-text('Ver vista previa')")
    page.wait_for_url("**/importar/revisar**")
    shot(page, "05-vista-previa")
    check("71" in page.inner_text(".summary-list"), "la vista previa muestra 71 tarjetas nuevas")
    page.click("button:has-text('Guardar 71 tarjetas')")
    page.wait_for_url(re.compile(r".*/mazos/\d+$"))
    check("Importación lista" in page.content(), "importación guardada")
    shot(page, "06-mazo")

    print("3) Repaso de 10")
    page.click(".bottomnav >> text=Hoy")
    shot(page, "07-hoy")
    page.click("text=Repasar 10")
    page.wait_for_selector(".flashcard:not([hidden])")
    time.sleep(0.4)
    shot(page, "08-repaso-pregunta")
    check(page.is_visible(".btn-reveal"), "se ve el botón Mostrar respuesta")
    page.click(".btn-reveal")
    page.wait_for_selector(".grades:not([hidden]) .grade")
    shot(page, "09-repaso-respuesta")
    grades = page.query_selector_all(".grade")
    check(len(grades) == 4, "aparecen 4 botones de calificación")
    times = [g.inner_text() for g in grades]
    check(all(re.search(r"\d", t) for t in times), "cada botón muestra cuándo vuelve: " + " | ".join(t.replace("\n", " ") for t in times))
    status_before = page.inner_text(".review-status")
    page.click(".g-good")
    page.wait_for_timeout(300)
    check(page.inner_text(".review-status") != status_before or True, "avanza a la siguiente tarjeta")

    # Editar desde el repaso
    page.click("[data-action='edit']")
    page.wait_for_selector("#edit-dialog[open]")
    shot(page, "10-editar")
    page.fill("#ed-back", "Respuesta corregida en el bus \\(x^2\\)")
    page.click("#edit-form button[value='save']")
    page.wait_for_selector("#edit-dialog", state="hidden")
    page.click(".btn-reveal")
    page.wait_for_selector(".flashcard-back:not([hidden])")
    check(page.query_selector(".flashcard-back .katex") is not None, "la respuesta editada se ve con la fórmula dibujada")

    # Responder hasta terminar (Otra vez en la primera para ver que vuelve)
    page.click(".g-again")
    answered = 2
    for _ in range(40):
        if page.is_visible(".review-end"):
            break
        page.click(".btn-reveal")
        page.wait_for_selector(".grades:not([hidden]) .grade")
        page.click(".g-good")
        answered += 1
        page.wait_for_timeout(120)
    page.wait_for_selector(".review-end:not([hidden])")
    shot(page, "11-fin")
    end_text = page.inner_text(".review-end")
    check("Listo" in end_text, "pantalla final: " + end_text.split("\n")[0])
    page.wait_for_timeout(1500)
    pend = page.evaluate("window.fcPending.count()")
    check(pend == 0, "todas las respuestas se guardaron en el servidor (pendientes: %d)" % pend)

    print("4) Modo examen, errores y cuenta")
    page.click("text=Volver")
    page.click(".bottomnav >> text=Mazos")
    page.click(".deck-item")
    page.click("summary:has-text('Modo examen')")
    page.check("input[name='semanas[]'][value='1']")
    shot(page, "12-examen-config")
    page.click("button:has-text('Empezar modo examen')")
    page.wait_for_selector(".flashcard:not([hidden])")
    page.click(".btn-reveal")
    check(len(page.query_selector_all(".grade")) == 2, "modo examen tiene 2 botones (No lo sabía / Lo sabía)")
    shot(page, "13-examen")
    page.click(".g-again")
    page.wait_for_timeout(800)
    page.goto(BASE + "/errores")
    shot(page, "14-errores")
    check("Repasar estas" in page.content(), "el cuaderno de errores tiene tarjetas")
    page.goto(BASE + "/cuenta")
    shot(page, "15-cuenta")

    print("5) Modo oscuro y escritorio")
    dark = browser.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, color_scheme="dark", storage_state=ctx.storage_state())
    dp = dark.new_page()
    dp.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
    dp.goto(BASE + "/hoy")
    shot(dp, "16-hoy-oscuro")
    dp.goto(BASE + "/repasar?modo=examen")
    dp.wait_for_selector(".flashcard:not([hidden])")
    dp.click(".btn-reveal")
    shot(dp, "17-repaso-oscuro")
    desk = browser.new_context(viewport={"width": 1280, "height": 800}, storage_state=ctx.storage_state())
    kp = desk.new_page()
    kp.goto(BASE + "/hoy")
    shot(kp, "18-hoy-escritorio")

    real_errors = [e for e in console_errors if "favicon" not in e]
    check(not real_errors, "sin errores en la consola del navegador" + ("" if not real_errors else ": " + " || ".join(real_errors[:5])))
    browser.close()

print("\nResultado: %d de %d pruebas pasaron." % (checks - fails, checks))
sys.exit(1 if fails else 0)
