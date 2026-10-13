// Todavía no hay fotos reales de categoría/producto (Producto.imagen_url e
// ícono de Categoria llegarán cuando el Admin las suba desde M04) — un
// ícono por palabra clave del nombre se ve mucho más terminado que un
// bloque de color vacío mientras tanto. Compartido entre HomeView y
// MenuView para no repetir el mapeo en dos archivos.
import type { Component } from 'vue'
import { Beef, CupSoda, Flame, IceCreamCone, Salad, Sandwich, Soup, Sparkles, UtensilsCrossed } from 'lucide-vue-next'

const iconosPorPalabra: [RegExp, Component][] = [
  [/hamburgu/i, Sandwich],
  [/salchipapa|papa/i, Flame],
  [/picada/i, Beef],
  [/especial/i, Sparkles],
  [/hot ?dog|perro/i, Soup],
  [/sandwich|sándwich/i, Sandwich],
  [/bebida|gaseosa|jugo|limonada/i, CupSoda],
  [/postre|helado|dulce/i, IceCreamCone],
  [/ensalada/i, Salad],
]

export function iconoPorNombre(nombre: string): Component {
  return iconosPorPalabra.find(([regex]) => regex.test(nombre))?.[1] ?? UtensilsCrossed
}
