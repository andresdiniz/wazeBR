import streamlit as st
import pandas as pd
import mysql.connector
from prophet import Prophet
import matplotlib.pyplot as plt

# === CONFIGURAÇÕES INICIAIS ===
st.set_page_config(page_title="Análise de Rotas", layout="wide")
st.title("📊 Previsão de Velocidade e Análise de Anomalias")

# === CONEXÃO COM O BANCO DE DADOS ===
def get_data():
    """Função para recuperar dados do banco MySQL"""
    conn = mysql.connector.connect(
        host='185.213.81.52',       # Substitua pelo IP ou domínio do seu banco de dados
        user='u335174317_wazeportal',         # Substitua pelo seu usuário
        password='@Ndre2025.',       # Substitua pela sua senha
        database='u335174317_wazeportal'        # Substitua pelo nome do seu banco de dados
    )
    query = "SELECT id, route_id, data, velocidade, tempo FROM historic_routes ORDER BY data"
    df = pd.read_sql(query, conn)
    conn.close()
    return df

# === COLETAR E PREPARAR DADOS ===
df = get_data()
df['data'] = pd.to_datetime(df['data'])

with st.expander("📋 Ver dados brutos"):
    st.dataframe(df)

# === FILTRO DE ROTA ===
route_id = st.selectbox("Escolha uma rota:", df['route_id'].unique())
df_filtered = df[df['route_id'] == route_id].tail(1000)

# === TRATAMENTO DE DADOS ===
df_filtered = df_filtered[df_filtered['velocidade'] < 150]
df_filtered = df_filtered.dropna(subset=['velocidade'])

# Verificação crítica de dados
if len(df_filtered) < 10:
    st.error("Dados insuficientes para análise após filtragem.")
    st.stop()

# === PREPARAR PARA PROPHET ===
df_prophet = df_filtered[['data', 'velocidade']].rename(columns={'data': 'ds', 'velocidade': 'y'})

# === TREINAR MODELO ===
try:
    model = Prophet(
        changepoint_prior_scale=0.05,
        seasonality_prior_scale=0.1,
        n_changepoints=25,
        daily_seasonality=True
    )
    model.fit(df_prophet)
except Exception as e:
    st.error(f"Falha crítica no modelo: {str(e)}")
    st.stop()

# === PREVISÃO ===
future = model.make_future_dataframe(periods=10, freq='3min')  # Corrigido
forecast = model.predict(future)

# === PLOTAR A PREVISÃO ===
st.subheader("🔮 Previsão de Velocidade (Próximos 30 minutos)")
fig1 = model.plot(forecast)
st.pyplot(fig1)

# === DETECTAR ANOMALIAS DE VELOCIDADE ===
df_filtered['vel_diff'] = df_filtered['velocidade'].diff().abs()
anomalias = df_filtered[df_filtered['vel_diff'] > 30]

st.subheader("🚨 Anomalias de velocidade detectadas")
st.dataframe(anomalias[['data', 'velocidade', 'vel_diff']])

# === GRÁFICO DE VELOCIDADE AO LONGO DO TEMPO ===
st.subheader("📈 Velocidade ao longo do tempo")
fig2, ax = plt.subplots()
ax.plot(df_filtered['data'], df_filtered['velocidade'], marker='o', label='Velocidade')
ax.set_title(f"Velocidade - Rota {route_id}")
ax.set_xlabel("Data/Hora")
ax.set_ylabel("Velocidade (km/h)")
ax.legend()
st.pyplot(fig2)
