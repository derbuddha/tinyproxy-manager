#!/usr/bin/env python3
"""
Generate a favicon for tinyproxy-manager
Creates a simple but professional icon representing a proxy/network service
"""

from PIL import Image, ImageDraw

# Create a 32x32 image with transparency
size = 32
img = Image.new('RGBA', (size, size), (0, 0, 0, 0))
draw = ImageDraw.Draw(img)

# Define colors - proxy/network theme (blue and gray)
bg_color = (41, 128, 185)  # Professional blue
accent_color = (236, 240, 241)  # Light gray/white
dark_accent = (52, 73, 94)  # Dark blue-gray

# Draw a rounded square background
margin = 2
draw.rounded_rectangle(
    [margin, margin, size - margin, size - margin],
    radius=4,
    fill=bg_color
)

# Draw a stylized "P" for Proxy with arrow/forward symbol
# This represents data flow through a proxy

# Draw the "P" letter
p_x = 7
p_y = 8
p_width = 10
p_height = 16

# Vertical bar of P
draw.rectangle([p_x, p_y, p_x + 3, p_y + p_height], fill=accent_color)

# Top arc of P
draw.ellipse([p_x, p_y, p_x + p_width, p_y + 8], fill=accent_color)
draw.ellipse([p_x + 2, p_y + 2, p_x + p_width - 2, p_y + 6], fill=bg_color)

# Draw forward arrow to represent proxy forwarding
arrow_x = 19
arrow_y = 14
# Arrow shaft
draw.rectangle([arrow_x, arrow_y, arrow_x + 6, arrow_y + 2], fill=accent_color)
# Arrow head
draw.polygon([
    (arrow_x + 5, arrow_y - 2),
    (arrow_x + 5, arrow_y + 4),
    (arrow_x + 9, arrow_y + 1)
], fill=accent_color)

# Save as .ico file with multiple sizes for better compatibility
img_16 = img.resize((16, 16), Image.Resampling.LANCZOS)
img_48 = img.resize((48, 48), Image.Resampling.LANCZOS)

# Save the favicon with multiple sizes
img.save(
    '/opt/stacks/tinyproxy-manager/gui/favicon.ico',
    format='ICO',
    sizes=[(16, 16), (32, 32), (48, 48)]
)

print("✓ favicon.ico generated successfully at gui/favicon.ico")
print("  Icon features a proxy symbol with forward arrow")
print("  Sizes: 16x16, 32x32, 48x48 pixels")
