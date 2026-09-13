<?php
// Icones desenhados na mao pro contexto especifico (bico, mesa, ventoinha,
// carretel) em vez de um icon-font generico. Todos usam stroke="currentColor"
// pra herdar a cor via CSS (classe .tone-hot / .tone-cool / .tone-idle etc).

function icon_nozzle(): string
{
    return <<<SVG
    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M11 5h10v7l-3 5v6a2 2 0 0 1-4 0v-6l-3-5V5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
      <line x1="11" y1="9" x2="21" y2="9" stroke="currentColor" stroke-width="1.6"/>
      <circle cx="16" cy="25.5" r="1.1" fill="currentColor"/>
    </svg>
    SVG;
}

function icon_bed(): string
{
    return <<<SVG
    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="5" y="12" width="22" height="6" rx="1" stroke="currentColor" stroke-width="1.6"/>
      <path d="M8 18v3M13 18v3M18 18v3M23 18v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      <path d="M7 24c1.5-1.3 3-1.3 4.5 0s3 1.3 4.5 0 3-1.3 4.5 0 3 1.3 4.5 0" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
    </svg>
    SVG;
}

function icon_chamber(): string
{
    return <<<SVG
    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="6" y="6" width="20" height="20" rx="2" stroke="currentColor" stroke-width="1.6"/>
      <path d="M16 11v8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
      <circle cx="16" cy="21.5" r="2.2" stroke="currentColor" stroke-width="1.6"/>
    </svg>
    SVG;
}

function icon_fan(bool $spinning): string
{
    $cls = $spinning ? 'icon-fan spin' : 'icon-fan';
    return <<<SVG
    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="{$cls}">
      <circle cx="16" cy="16" r="12" stroke="currentColor" stroke-width="1.3" opacity="0.3"/>
      <g fill="currentColor">
        <g>
          <ellipse cx="16" cy="9.2" rx="2.3" ry="5.4" transform="rotate(8 16 9.2)"/>
        </g>
        <g transform="rotate(120 16 16)">
          <ellipse cx="16" cy="9.2" rx="2.3" ry="5.4" transform="rotate(8 16 9.2)"/>
        </g>
        <g transform="rotate(240 16 16)">
          <ellipse cx="16" cy="9.2" rx="2.3" ry="5.4" transform="rotate(8 16 9.2)"/>
        </g>
      </g>
      <circle cx="16" cy="16" r="2" fill="currentColor" opacity="0.9"/>
    </svg>
    SVG;
}

function icon_spool(string $colorCss): string
{
    return <<<SVG
    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="16" cy="16" r="11" stroke="currentColor" stroke-width="1.4" opacity="0.35"/>
      <circle cx="16" cy="16" r="7.5" fill="none" stroke="{$colorCss}" stroke-width="4"/>
      <circle cx="16" cy="16" r="2.2" fill="currentColor" opacity="0.6"/>
    </svg>
    SVG;
}

function icon_wifi(int $bars): string
{
    $heights = [6, 11, 16, 21];
    $rects = [];
    foreach ($heights as $i => $h) {
        $x = 5 + $i * 6.5;
        $y = 24 - $h;
        $op = $bars >= ($i + 1) ? '1' : '0.25';
        $rects[] = "<rect x=\"{$x}\" y=\"{$y}\" width=\"4\" height=\"{$h}\" rx=\"1\" fill=\"currentColor\" opacity=\"{$op}\"/>";
    }
    $rectsHtml = implode("\n      ", $rects);
    return <<<SVG
    <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
      {$rectsHtml}
    </svg>
    SVG;
}

function icon_state_dot(string $tone): string
{
    return <<<SVG
    <svg viewBox="0 0 12 12" width="10" height="10" class="state-dot tone-{$tone}">
      <circle cx="6" cy="6" r="5" fill="currentColor"/>
    </svg>
    SVG;
}
