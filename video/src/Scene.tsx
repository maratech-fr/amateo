import React from "react";
import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";
import { COLORS, FONTS } from "./brand";
import { BrandMark } from "./BrandMark";
import type { Plan } from "./script";

// Fond d'un plan. Les plans de CAPTURE affichent un emplacement reserve (la capture reelle est
// montee plus tard) ; les plans MOTION et LOGO affichent un visuel de reperage sur le fond papier.
// Volontairement sobre : ce squelette sert a JUGER le decoupage et le timing, pas le rendu final.

const muted = "#6d675d";

const PostIts: React.FC = () => {
  // Positions deterministes (reperage « charge mentale »), teintes des trois arcs du logo.
  const notes: ReadonlyArray<{ x: number; y: number; r: number; c: string }> = [
    { x: 300, y: 230, r: -7, c: COLORS.magenta },
    { x: 760, y: 170, r: 5, c: COLORS.orange },
    { x: 1180, y: 250, r: -4, c: COLORS.teal },
    { x: 540, y: 520, r: 6, c: COLORS.orange },
    { x: 1010, y: 560, r: -6, c: COLORS.magenta },
    { x: 1380, y: 600, r: 4, c: COLORS.teal },
  ];
  return (
    <>
      {notes.map((n, i) => (
        <div
          key={i}
          style={{
            position: "absolute",
            left: n.x,
            top: n.y,
            width: 200,
            height: 170,
            transform: `rotate(${n.r}deg)`,
            background: `color-mix(in srgb, ${n.c} 18%, #ffffff)`,
            borderLeft: `6px solid ${n.c}`,
            borderRadius: 8,
            boxShadow: "0 14px 34px -18px rgba(25,23,20,0.5)",
          }}
        />
      ))}
    </>
  );
};

const Label: React.FC<{ text: string }> = ({ text }) => (
  <div
    style={{
      position: "absolute",
      top: 64,
      left: 0,
      right: 0,
      textAlign: "center",
      fontFamily: FONTS.body,
      fontSize: 24,
      letterSpacing: "0.06em",
      textTransform: "uppercase",
      color: muted,
    }}
  >
    {text}
  </div>
);

const CapturePlaceholder: React.FC<{ plan: Plan }> = ({ plan }) => (
  <AbsoluteFill style={{ alignItems: "center", justifyContent: "center" }}>
    <div
      style={{
        width: 1480,
        borderRadius: 22,
        background: "#ffffff",
        border: `1px solid ${COLORS.line}`,
        boxShadow: "0 30px 80px -36px rgba(25,23,20,0.4)",
        overflow: "hidden",
      }}
    >
      <div
        style={{
          height: 54,
          display: "flex",
          alignItems: "center",
          gap: 10,
          padding: "0 22px",
          background: COLORS.paper,
          borderBottom: `1px solid ${COLORS.line}`,
        }}
      >
        {[COLORS.magenta, COLORS.orange, COLORS.teal].map((c) => (
          <span key={c} style={{ width: 13, height: 13, borderRadius: 999, background: c }} />
        ))}
      </div>
      <div style={{ padding: "72px 72px 84px", textAlign: "center" }}>
        <div style={{ fontFamily: `"${FONTS.display}", ${FONTS.body}`, fontWeight: 800, fontSize: 132, color: COLORS.tealInk, letterSpacing: "-0.02em" }}>
          {plan.id}
        </div>
        <div style={{ fontFamily: FONTS.body, fontSize: 30, color: COLORS.ink, marginTop: 10 }}>
          Capture de l'application — emplacement reserve
        </div>
        <div style={{ fontFamily: FONTS.body, fontSize: 25, color: muted, marginTop: 22, lineHeight: 1.45, maxWidth: 1100, marginLeft: "auto", marginRight: "auto" }}>
          {plan.shot}
        </div>
        {plan.captures ? (
          <div style={{ fontFamily: FONTS.body, fontSize: 21, color: COLORS.tealInk, marginTop: 26 }}>
            {plan.captures.join("  ·  ")}
          </div>
        ) : null}
      </div>
    </div>
  </AbsoluteFill>
);

const LogoPlaceholder: React.FC<{ plan: Plan }> = ({ plan }) => (
  <AbsoluteFill style={{ alignItems: "center", justifyContent: "center" }}>
    <BrandMark size={460} />
    <Label text={plan.kind === "logo-intro" ? "Animation du logo — ouverture" : "Animation du logo — fermeture"} />
    <div style={{ position: "absolute", bottom: 210, left: 0, right: 0, textAlign: "center", fontFamily: FONTS.body, fontSize: 22, color: muted }}>
      images exportees de public/logo/ au montage (npm run logo)
    </div>
  </AbsoluteFill>
);

export const Scene: React.FC<{ plan: Plan }> = ({ plan }) => {
  const frame = useCurrentFrame();
  const opacity = interpolate(frame, [0, 6], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  return (
    <AbsoluteFill style={{ backgroundColor: COLORS.paper, opacity }}>
      {plan.kind === "motion" ? (
        <>
          <Label text={plan.n === 11 ? "Motion design — confiance" : "Motion design — charge mentale"} />
          {plan.n !== 11 ? <PostIts /> : null}
        </>
      ) : null}
      {plan.kind === "capture" ? <CapturePlaceholder plan={plan} /> : null}
      {plan.kind === "logo-intro" || plan.kind === "logo-outro" ? <LogoPlaceholder plan={plan} /> : null}
    </AbsoluteFill>
  );
};
