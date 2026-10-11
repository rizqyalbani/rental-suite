from pathlib import Path

from PIL import Image, ImageDraw, ImageFont, ImageOps


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "public" / "icons"
OUT.mkdir(parents=True, exist_ok=True)

START = (0x4F, 0x46, 0xE5)
END = (0x1D, 0x4E, 0xD8)

FONT_CANDIDATES = [
    "/System/Library/Fonts/Supplemental/Arial Bold.ttf",
    "/System/Library/Fonts/Supplemental/Arial.ttf",
    "/Library/Fonts/Arial Bold.ttf",
    "/Library/Fonts/Arial.ttf",
    "/System/Library/Fonts/Supplemental/Helvetica Bold.ttf",
    "/System/Library/Fonts/Supplemental/Helvetica.ttf",
]


def load_font(size: int) -> ImageFont.ImageFont:
    for path in FONT_CANDIDATES:
        try:
            return ImageFont.truetype(path, size=size)
        except Exception:
            pass
    return ImageFont.load_default()


def make_icon(size: int, radius: int, maskable: bool = False) -> Image.Image:
    gradient = Image.linear_gradient("L").resize((size, size)).rotate(45, expand=False)
    bg = ImageOps.colorize(gradient, START, END).convert("RGBA")

    if maskable:
        img = bg
    else:
        mask = Image.new("L", (size, size), 0)
        md = ImageDraw.Draw(mask)
        md.rounded_rectangle((0, 0, size, size), radius=radius, fill=255)
        img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        img.paste(bg, (0, 0), mask=mask)

    draw = ImageDraw.Draw(img)
    font = load_font(int(size * 0.56))
    text = "R"
    bbox = draw.textbbox((0, 0), text, font=font)
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    x = (size - tw) // 2 - bbox[0]
    y = (size - th) // 2 - bbox[1]
    draw.text((x, y), text, fill=(255, 255, 255, 255), font=font)
    return img


def main() -> None:
    targets = [
        (OUT / "icon-192.png", 192, int(192 * 0.21), False),
        (OUT / "icon-512.png", 512, int(512 * 0.21), False),
        (OUT / "icon-maskable-512.png", 512, 0, True),
        (OUT / "apple-touch-icon.png", 180, int(180 * 0.21), False),
    ]
    for path, size, radius, maskable in targets:
        img = make_icon(size=size, radius=radius, maskable=maskable)
        img.save(path, format="PNG", optimize=True)
        print(f"Wrote {path}")


if __name__ == "__main__":
    main()

