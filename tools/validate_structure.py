#!/usr/bin/env python3
"""Validate standalone Magento module invariants without a Magento installation."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from xml.etree import ElementTree


ROOT = Path(__file__).resolve().parents[1]
MODULE_NAME = "Aavirbhava_AiShoppingAssistant"
PHP_NAMESPACE = "Aavirbhava\\AiShoppingAssistant"
PACKAGE_NAME = "aavirbhava/module-ai-shopping-assistant"


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def validate_composer() -> None:
    composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
    if composer.get("name") != PACKAGE_NAME:
        fail("Composer package name does not match the permanent identity.")

    psr4 = composer.get("autoload", {}).get("psr-4", {})
    if psr4.get(f"{PHP_NAMESPACE}\\") != "":
        fail("Composer PSR-4 namespace does not map to the package root.")


def validate_xml() -> None:
    xml_files = sorted(ROOT.rglob("*.xml"))
    if not xml_files:
        fail("No Magento XML files were found.")

    for path in xml_files:
        try:
            ElementTree.parse(path)
        except ElementTree.ParseError as error:
            fail(f"Invalid XML in {path.relative_to(ROOT)}: {error}")

    module_xml = (ROOT / "etc/module.xml").read_text(encoding="utf-8")
    if f'name="{MODULE_NAME}"' not in module_xml:
        fail("etc/module.xml does not declare the permanent module identity.")


def validate_php_identities() -> None:
    registration = (ROOT / "registration.php").read_text(encoding="utf-8")
    if f"'{MODULE_NAME}'" not in registration:
        fail("registration.php does not register the permanent module identity.")

    namespace_pattern = re.compile(r"^namespace\s+([^;]+);", re.MULTILINE)
    roots = [ROOT / "Api", ROOT / "Model", ROOT / "Test"]
    for php_root in roots:
        if not php_root.exists():
            continue
        for path in php_root.rglob("*.php"):
            content = path.read_text(encoding="utf-8")
            match = namespace_pattern.search(content)
            if path.name == "bootstrap.php":
                continue
            if match is None or not match.group(1).startswith(PHP_NAMESPACE):
                fail(f"Unexpected or missing namespace in {path.relative_to(ROOT)}.")


def validate_no_placeholder() -> None:
    checked_suffixes = {".php", ".xml", ".json"}
    forbidden = ("Vendor_AiShoppingAssistant", "Vendor\\AiShoppingAssistant")
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix not in checked_suffixes:
            continue
        content = path.read_text(encoding="utf-8")
        for value in forbidden:
            if value in content:
                fail(f"Placeholder identity remains in {path.relative_to(ROOT)}.")


def main() -> None:
    validate_composer()
    validate_xml()
    validate_php_identities()
    validate_no_placeholder()
    print("Standalone module structure is valid.")


if __name__ == "__main__":
    main()
